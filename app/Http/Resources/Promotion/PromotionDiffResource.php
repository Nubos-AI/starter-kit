<?php

declare(strict_types=1);

namespace App\Http\Resources\Promotion;

use App\DTOs\Promotion\ArtifactDiff;
use App\DTOs\Promotion\RenameHint;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\ConfigBundle\ArtifactWriteAction;
use App\Enums\Promotion\ConflictResolution;
use App\Models\PromotionRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PromotionRun
 */
class PromotionDiffResource extends JsonResource
{
    private string $pulledInReason = 'i18n.backend.http.resources.promotion.promotion_diff_resource.included_because_a_selected_artifact_depends_on_it';

    /**
     * @var array{
     *     groups: list<array{kind: ArtifactKind, unchanged_count: int, rename_hints: list<RenameHint>, rows: list<array{diff: ArtifactDiff, is_selected: bool, is_pulled_in: bool, refusal_reason: string|null, is_refused_by_target: bool, overwrite_consequence: string|null, is_overwritten: bool, decision: ConflictResolution|null}>}>,
     *     submit_blocker: string|null,
     *     has_outdated_decisions: bool,
     *     undecided_count: int,
     *     source_error: string|null,
     * }
     */
    private array $preview;

    /**
     * @param  array{
     *     groups: list<array{kind: ArtifactKind, unchanged_count: int, rename_hints: list<RenameHint>, rows: list<array{diff: ArtifactDiff, is_selected: bool, is_pulled_in: bool, refusal_reason: string|null, is_refused_by_target: bool, overwrite_consequence: string|null, is_overwritten: bool, decision: ConflictResolution|null}>}>,
     *     submit_blocker: string|null,
     *     has_outdated_decisions: bool,
     *     undecided_count: int,
     *     source_error: string|null,
     * }  $preview
     */
    public function withPreview(array $preview): self
    {
        $this->preview = $preview;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PromotionRun $run */
        $run = $this->resource;

        $header = (new PromotionRunResource($run))->toArray($request);
        $lockReason = is_string($header['update_reason']) ? $header['update_reason'] : null;
        $submitReason = $lockReason ?? $this->preview['submit_blocker'];

        return [
            'run' => [
                ...$header,
                'can_submit' => $submitReason === null,
                'submit_reason' => $submitReason,
                'has_outdated_decisions' => $this->preview['has_outdated_decisions'],
                'undecided_count' => $this->preview['undecided_count'],
            ],
            'groups' => array_map(fn (array $group): array => [
                'kind' => $group['kind']->value,
                'unchanged_count' => $group['unchanged_count'],
                'rename_hints' => array_map(static fn (RenameHint $hint): array => [
                    'from_key' => $hint->fromKey,
                    'to_key' => $hint->toKey,
                    'message' => __('i18n.backend.http.resources.promotion.promotion_diff_resource.will_be_deleted_and_created_if_you_renamed_it', ['value1' => $hint->fromKey, 'value2' => $hint->toKey]),
                ], $group['rename_hints']),
                'rows' => array_map(fn (array $row): array => [
                    'kind' => $row['diff']->kind->value,
                    'key' => $row['diff']->key,
                    'state' => $row['diff']->state->value,
                    'changed_paths' => $row['diff']->changedPaths,
                    'is_selected' => $row['is_selected'],
                    'is_pulled_in' => $row['is_pulled_in'],
                    'pulled_in_reason' => $row['is_pulled_in'] ? __($this->pulledInReason) : null,
                    'refusal_reason' => $row['refusal_reason'],
                    'decision' => $row['decision']?->value,
                    'can_select' => $lockReason === null && !$row['is_pulled_in'] && $this->isSelectable($row),
                    'select_reason' => $lockReason ?? ($row['is_pulled_in'] ? __($this->pulledInReason) : ($this->isSelectable($row) ? null : $row['refusal_reason'])),
                    'can_overwrite' => $lockReason === null && $row['overwrite_consequence'] !== null,
                    'overwrite' => $row['is_overwritten'],
                    'overwrite_consequence' => $row['overwrite_consequence'],
                ], $group['rows']),
            ], $this->preview['groups']),
            'decision_options' => array_map(static fn (ConflictResolution $resolution): array => [
                'value' => $resolution->value,
                'label' => $resolution->label(),
            ], ConflictResolution::cases()),
            'source_error' => $this->preview['source_error'],
            'report' => $this->reportOf($run),
        ];
    }

    /**
     * @param  array{is_selected: bool, is_refused_by_target: bool, overwrite_consequence: string|null}  $row
     */
    private function isSelectable(array $row): bool
    {
        return !$row['is_refused_by_target'] || $row['is_selected'] || $row['overwrite_consequence'] !== null;
    }

    /**
     * @return array{error: string|null, written_count: int, skipped_count: int, notes: list<string>, results: list<array{kind: string, key: string, action: string, notes: list<string>}>}|null
     */
    private function reportOf(PromotionRun $run): ?array
    {
        $report = $run->report;

        if ($report === null) {
            return null;
        }

        $results = [];

        foreach (is_array($report['results'] ?? null) ? $report['results'] : [] as $result) {
            $results[] = [
                'kind' => (string) data_get($result, 'kind', ''),
                'key' => (string) data_get($result, 'key', ''),
                'action' => (string) data_get($result, 'action', ''),
                'notes' => $this->stringsOf(data_get($result, 'notes')),
            ];
        }

        $skipped = count(array_filter($results, static fn (array $result): bool => $result['action'] === ArtifactWriteAction::Skipped->value));

        return [
            'error' => is_string($report['error'] ?? null) ? $report['error'] : null,
            'written_count' => count($results) - $skipped,
            'skipped_count' => $skipped,
            'notes' => $this->stringsOf($report['notes'] ?? null),
            'results' => $results,
        ];
    }

    /**
     * @return list<string>
     */
    private function stringsOf(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }
}
