<?php

declare(strict_types=1);

namespace App\Support\Export;

use App\Enums\Export\ExportFormat;
use App\Models\CustomRecord;
use App\Models\ExportJob;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\Segment;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Engine\RecordFilterCompiler;
use App\Support\Engine\RecordSelectionResolver;
use App\Support\Reports\ReportExecutionContext;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;

class RecordExportRunner
{
    private int $chunkSize = 500;

    public function __construct(
        private readonly ObjectTypeRegistry $objectTypes,
        private readonly ExportColumnResolver $columnResolver,
        private readonly CsvExportWriter $csvWriter,
        private readonly XlsxExportWriter $xlsxWriter,
        private readonly JsonExportWriter $jsonWriter,
        private readonly RecordSelectionResolver $selectionResolver,
        private readonly RecordFilterCompiler $filterCompiler,
        private readonly ReportExecutionContext $executionContext,
    ) {}

    public function run(string $tenantId, string $actingUserId, string $exportJobId): void
    {
        $this->executionContext->runAsViewer(
            $tenantId,
            $actingUserId,
            function (User $actingUser) use ($tenantId, $exportJobId): void {
                $this->export($tenantId, $actingUser, $exportJobId);
            },
        );
    }

    private function export(string $tenantId, User $actingUser, string $exportJobId): void
    {
        $exportJob = ExportJob::query()->whereKey($exportJobId)->first();

        if (!$exportJob instanceof ExportJob) {
            throw new RuntimeException(__('i18n.backend.support.export.record_export_runner.the_export_job_row_could_not_be_resolved'));
        }

        $objectType = $this->objectTypes->byId($exportJob->object_type_id);
        $objectType->load('fieldDefinitions');

        $format = $exportJob->format;
        $scope = $exportJob->scope;
        $fields = $exportJob->fields;

        $plan = $this->columnResolver->resolve($actingUser, $objectType, $fields);

        $query = $this->scopedQuery($objectType, $scope, $tenantId, $actingUser);

        $disk = (string) config('engine.bulk.export_disk', config('filesystems.default'));
        $path = "exports/{$tenantId}/{$exportJobId}.{$format->extension()}";

        $rowCount = $this->writerFor($format)->write(
            $disk,
            $path,
            $plan['headers'],
            $this->stream($query, $this->columnResolver, $plan, $tenantId),
        );

        $exportJob->forceFill([
            'result_path' => $path,
            'row_count' => $rowCount,
        ])->save();
    }

    /**
     * @param  Builder<CustomRecord>  $query
     * @param  array{headers: list<string>, descriptors: list<array<string, mixed>>}  $plan
     * @return Generator<int, array<string, mixed>>
     */
    private function stream(Builder $query, ExportColumnResolver $resolver, array $plan, string $tenantId): Generator
    {
        $lastId = null;

        do {
            $chunkQuery = (clone $query)->orderBy('id')->limit($this->chunkSize);

            if ($lastId !== null) {
                $chunkQuery->where('id', '>', $lastId);
            }

            $records = $chunkQuery->get();

            if ($records->isEmpty()) {
                break;
            }

            foreach ($resolver->rowsForChunk($plan, $records, $tenantId) as $row) {
                yield $row;
            }

            $lastId = (string) $records->last()->getKey();
        } while ($records->count() === $this->chunkSize);
    }

    private function writerFor(ExportFormat $format): CsvExportWriter|XlsxExportWriter|JsonExportWriter
    {
        return match ($format) {
            ExportFormat::Csv => $this->csvWriter,
            ExportFormat::Xlsx => $this->xlsxWriter,
            ExportFormat::Json => $this->jsonWriter,
        };
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return Builder<CustomRecord>
     */
    public function scopedQuery(ObjectType $objectType, array $scope, string $tenantId, User $actingUser): Builder
    {
        $mode = is_string($scope['mode'] ?? null) ? $scope['mode'] : 'whole-type';

        if ($mode === 'view') {
            $filterModel = is_array($scope['filterModel'] ?? null) ? $scope['filterModel'] : [];
            $filterScope = $this->selectionResolver->filterScope($objectType, $actingUser);

            $query = $this->selectionResolver
                ->scopedQuery(
                    $objectType,
                    [
                        'mode' => 'all-matching',
                        'filterModel' => $filterModel,
                        'search' => is_string($scope['search'] ?? null) ? $scope['search'] : null,
                    ],
                    $filterScope['fields'],
                    $filterScope['expressions'],
                    $filterScope['readable'],
                )
                ->where('tenant_id', $tenantId);

            if (!$this->everyFilterResolves($filterModel, $filterScope['fields'])) {
                $query->whereRaw('1 = 0');
            }

            return $query;
        }

        if ($mode === 'segment') {
            return $this->segmentQuery(
                $objectType,
                is_string($scope['segmentId'] ?? null) ? $scope['segmentId'] : '',
                $tenantId,
                $actingUser,
            );
        }

        return $this->baseQuery($objectType, $tenantId);
    }

    /**
     * @param  array<array-key, mixed>  $filterModel
     * @param  Collection<int, FieldDefinition>  $allowedFields
     */
    private function everyFilterResolves(array $filterModel, Collection $allowedFields): bool
    {
        foreach (array_keys($filterModel) as $colId) {
            $resolved = $allowedFields->contains(
                static fn (FieldDefinition $field): bool => $field->key === (string) $colId,
            );

            if (!$resolved) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return Builder<CustomRecord>
     */
    private function segmentQuery(ObjectType $objectType, string $segmentId, string $tenantId, User $actingUser): Builder
    {
        $query = $this->baseQuery($objectType, $tenantId);

        $segment = $this->segmentFor($segmentId);

        if (!$segment instanceof Segment || !$actingUser->can('view', $segment)) {
            return $query->whereRaw('1 = 0');
        }

        $filterScope = $this->selectionResolver->filterScope($objectType, $actingUser);

        $this->filterCompiler->applyTree(
            $query,
            $filterScope['fields'],
            $segment->filter_definition ?? [],
            FieldVisibilityResolver::forRequest(),
            $objectType,
            $filterScope['expressions'],
        );

        return $query;
    }

    protected function segmentFor(string $segmentId): ?Segment
    {
        return Segment::query()->whereKey($segmentId)->first();
    }

    /**
     * @return Builder<CustomRecord>
     */
    private function baseQuery(ObjectType $objectType, string $tenantId): Builder
    {
        return CustomRecord::query()
            ->ofType($objectType)
            ->where('tenant_id', $tenantId);
    }
}
