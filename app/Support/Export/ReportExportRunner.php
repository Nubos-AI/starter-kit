<?php

declare(strict_types=1);

namespace App\Support\Export;

use App\DTOs\Reports\ReportDefinitionData;
use App\DTOs\Reports\ReportResultData;
use App\Enums\Export\ExportFormat;
use App\Enums\Export\ExportJobStatus;
use App\Enums\Reports\ReportExecutionMode;
use App\Models\ExportJob;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\Report;
use App\Models\User;
use App\Scopes\TenantScope;
use App\Support\I18n\TranslatableValueResolver;
use App\Support\Reports\ReportDefinitionValidator;
use App\Support\Reports\ReportExecutionContext;
use App\Support\Reports\ReportExecutionModeResolver;
use App\Support\Reports\ReportInputRules;
use App\Support\Reports\ReportRunner;
use App\Traits\Export\BuildsUniqueHeaders;
use RuntimeException;
use Throwable;

class ReportExportRunner
{
    use BuildsUniqueHeaders;

    private string $otherLabel = 'i18n.backend.support.export.report_export_runner.other';

    private string $unspecifiedLabel = 'i18n.backend.support.export.report_export_runner.not_specified';

    public function __construct(
        private readonly ReportExecutionContext $executionContext,
        private readonly ReportExecutionModeResolver $modeResolver,
        private readonly ReportDefinitionValidator $definitionValidator,
        private readonly ReportRunner $reportRunner,
        private readonly CsvExportWriter $csvWriter,
        private readonly XlsxExportWriter $xlsxWriter,
    ) {}

    /**
     * @throws Throwable
     */
    public function run(string $tenantId, string $actingUserId, string $exportJobId): int
    {
        $exportJob = $this->resolveJob($tenantId, $exportJobId);

        try {
            $report = $this->resolveReport($tenantId, $exportJob);
            $objectType = ObjectType::query()->whereKey($report->object_type_id)->firstOrFail();

            $run = fn (User $actingUser): array => $this->execute($report, $objectType, $actingUser);

            /** @var array{ReportDefinitionData, ReportResultData} $executed */
            $executed = $this->modeResolver->effectiveMode($report) === ReportExecutionMode::Definer
                ? $this->executionContext->runAsDefiner($tenantId, $report->owner_id, $run)
                : $this->executionContext->runAsViewer($tenantId, $actingUserId, $run);

            [$definition, $result] = $executed;

            $table = $this->table($definition, $result);
            $format = $exportJob->format;
            $path = "exports/{$tenantId}/{$exportJobId}.{$format->extension()}";

            $this->writerFor($format)->write(
                (string) config('engine.bulk.export_disk', config('filesystems.default')),
                $path,
                $table['headers'],
                $table['rows'],
                $table['numericHeaders'],
            );

            $this->markCompleted($exportJob, $path, count($result->rows));

            return count($result->rows);
        } catch (Throwable $exception) {
            $this->markFailed($exportJob);

            throw $exception;
        }
    }

    public function finalize(string $tenantId, string $exportJobId): ?ExportJobStatus
    {
        $exportJob = ExportJob::query()
            ->withoutGlobalScopes([TenantScope::class])
            ->where('tenant_id', $tenantId)
            ->whereKey($exportJobId)
            ->first();

        if (!$exportJob instanceof ExportJob) {
            return null;
        }

        if ($exportJob->result_path === null) {
            $this->markFailed($exportJob);

            return ExportJobStatus::Failed;
        }

        $this->markCompleted($exportJob, $exportJob->result_path, $exportJob->row_count);

        return ExportJobStatus::Completed;
    }

    /**
     * @return array{ReportDefinitionData, ReportResultData}
     */
    private function execute(Report $report, ObjectType $objectType, User $actingUser): array
    {
        $definition = $this->definitionValidator->validate(
            $report->only(ReportInputRules::definitionKeys()),
            $objectType,
            $actingUser,
        );

        return [$definition, $this->reportRunner->run($definition)];
    }

    private function resolveJob(string $tenantId, string $exportJobId): ExportJob
    {
        return ExportJob::query()
            ->withoutGlobalScopes([TenantScope::class])
            ->where('tenant_id', $tenantId)
            ->whereKey($exportJobId)
            ->firstOrFail();
    }

    private function resolveReport(string $tenantId, ExportJob $exportJob): Report
    {
        $reportId = $exportJob->scope['reportId'] ?? null;

        if (!is_string($reportId) || $reportId === '') {
            throw new RuntimeException(__('i18n.backend.support.export.report_export_runner.the_export_job_carries_no_report_reference'));
        }

        return Report::query()
            ->withoutGlobalScopes([TenantScope::class])
            ->where('tenant_id', $tenantId)
            ->whereKey($reportId)
            ->firstOrFail();
    }

    /**
     * @return array{headers: list<string>, numericHeaders: list<string>, rows: list<array<string, string>>}
     */
    private function table(ReportDefinitionData $definition, ReportResultData $result): array
    {
        /** @var array<string, true> $taken */
        $taken = [];

        $groupHeader = $this->uniqueHeader($this->label($definition->groupByField) ?? __('i18n.backend.support.export.report_export_runner.group'), $taken);
        $seriesHeader = $definition->seriesField instanceof FieldDefinition
            ? $this->uniqueHeader((string) $this->label($definition->seriesField), $taken)
            : null;
        $valueHeader = $this->uniqueHeader(__('i18n.backend.support.export.report_export_runner.value'), $taken);
        $countHeader = $this->uniqueHeader(__('i18n.backend.support.export.report_export_runner.records'), $taken);

        $headers = [$groupHeader];

        if ($seriesHeader !== null) {
            $headers[] = $seriesHeader;
        }

        $headers[] = $valueHeader;
        $headers[] = $countHeader;

        $rows = [];

        foreach ($result->rows as $row) {
            $cells = [$groupHeader => $row->isOtherGroup ? __($this->otherLabel) : ($row->groupValue ?? __($this->unspecifiedLabel))];

            if ($seriesHeader !== null) {
                $cells[$seriesHeader] = $row->isOtherSeries ? __($this->otherLabel) : ($row->seriesValue ?? __($this->unspecifiedLabel));
            }

            $cells[$valueHeader] = $row->value ?? '';
            $cells[$countHeader] = (string) $row->recordCount;

            $rows[] = $cells;
        }

        foreach ($this->footerLines($result) as $line) {
            $rows[] = [...array_fill_keys($headers, ''), $groupHeader => $line];
        }

        return [
            'headers' => $headers,
            'numericHeaders' => [$valueHeader, $countHeader],
            'rows' => $rows,
        ];
    }

    /**
     * @return list<string>
     */
    private function footerLines(ReportResultData $result): array
    {
        $lines = [
            "Stand: {$result->generatedAt}",
            __('i18n.backend.support.export.report_export_runner.total').($result->total ?? '—'),
            "Verworfene Werte: {$result->discardedValueCount}",
        ];

        if ($result->isSuppressed) {
            $lines[] = __('i18n.backend.support.export.report_export_runner.result_suppressed_too_few_records_for_an_anonymous_report');

            return $lines;
        }

        if ($result->rows === []) {
            $lines[] = __('i18n.backend.support.export.report_export_runner.no_records_in_the_result');
        }

        return $lines;
    }

    private function label(?FieldDefinition $field): ?string
    {
        if (!$field instanceof FieldDefinition) {
            return null;
        }

        $label = (new TranslatableValueResolver)->resolve($field->i18n_labels);

        return is_string($label) && $label !== '' ? $label : $field->key;
    }

    private function writerFor(ExportFormat $format): CsvExportWriter|XlsxExportWriter
    {
        return match ($format) {
            ExportFormat::Csv => $this->csvWriter,
            ExportFormat::Xlsx => $this->xlsxWriter,
            ExportFormat::Json => throw new RuntimeException(__('i18n.backend.support.export.report_export_runner.the_report_export_supports_csv_and_xlsx_only')),
        };
    }

    private function markCompleted(ExportJob $exportJob, string $path, int $rowCount): void
    {
        $exportJob->forceFill([
            'status' => ExportJobStatus::Completed,
            'result_path' => $path,
            'row_count' => $rowCount,
            'finished_at' => now(),
        ])->save();
    }

    private function markFailed(ExportJob $exportJob): void
    {
        $exportJob->forceFill([
            'status' => ExportJobStatus::Failed,
            'finished_at' => now(),
        ])->save();
    }
}
