<?php

declare(strict_types=1);

namespace App\Traits\ConfigBundle;

use App\DTOs\ConfigBundle\ArtifactWriteResult;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\ConfigBundle\ArtifactWriteAction;
use App\Enums\Promotion\DiffState;
use Illuminate\Database\Eloquent\Model;

trait ReportsArtifactWriteResults
{
    private function skipped(ArtifactKind $kind, string $key, string $reason): ArtifactWriteResult
    {
        return new ArtifactWriteResult($kind, $key, ArtifactWriteAction::Skipped, null, [$reason]);
    }

    /**
     * @param  list<string>  $notes
     */
    private function written(ArtifactKind $kind, string $key, ArtifactWriteAction $action, object $model, array $notes = [], ?string $awaitedReference = null): ArtifactWriteResult
    {
        /** @var Model $model */
        return new ArtifactWriteResult($kind, $key, $action, (string) $model->getKey(), $notes, $awaitedReference);
    }

    private function skippedByState(ArtifactKind $kind, string $key, DiffState $state): ArtifactWriteResult
    {
        return $this->skipped(
            $kind,
            $key,
            __('i18n.backend.traits.config_bundle.reports_artifact_write_results.the_comparison_reports_this_artifact_as_nothing_will_be', ['value1' => $state->value]),
        );
    }

    private function skippedByPlaceholder(ArtifactKind $kind, string $key): ArtifactWriteResult
    {
        return $this->skipped(
            $kind,
            $key,
            __('i18n.backend.traits.config_bundle.reports_artifact_write_results.the_payload_still_contains_a_placeholder_for_a_reference'),
        );
    }

    private function missingTarget(ArtifactKind $kind, string $key): ArtifactWriteResult
    {
        return $this->skipped($kind, $key, $this->missingTargetReason($key));
    }

    private function missingTargetReason(string $key): string
    {
        return __('i18n.backend.traits.config_bundle.reports_artifact_write_results.the_target_tenant_has_no_counterpart_under_the_key', ['value1' => $key]);
    }

    private function unresolvable(ArtifactKind $kind, string $key, string $column, string $reference): ArtifactWriteResult
    {
        $reason = __('i18n.backend.traits.config_bundle.reports_artifact_write_results.the_reference_to_cannot_be_resolved_in_the_target', ['value1' => $column, 'value2' => $reference]);

        return new ArtifactWriteResult($kind, $key, ArtifactWriteAction::Skipped, null, [$reason], $reason);
    }

    private function ambiguous(ArtifactKind $kind, string $key): ArtifactWriteResult
    {
        return $this->skipped($kind, $key, __('i18n.backend.traits.config_bundle.reports_artifact_write_results.the_target_tenant_has_more_than_one_matching_row', ['value1' => $key]));
    }

    private function unsupported(ArtifactKind $kind, string $key): ArtifactWriteResult
    {
        return $this->skipped($kind, $key, __('i18n.backend.traits.config_bundle.reports_artifact_write_results.the_kind_does_not_belong_to_this_writer_and', ['value1' => $kind->value]));
    }

    /**
     * @return list<string>
     */
    private function fallbackNotes(DiffState $state, string $subject): array
    {
        return $state === DiffState::Added ? [$this->fallbackNote($subject)] : [];
    }

    private function fallbackNote(string $subject): string
    {
        return __('i18n.backend.traits.config_bundle.reports_artifact_write_results.the_artifact_was_reported_as_new_but_the_target', ['value1' => $subject]);
    }

    private function carriesPlaceholder(mixed $value): bool
    {
        $placeholder = config('engine.config_bundle.placeholder');

        return is_string($placeholder) && $placeholder !== '' && $this->containsText($value, $placeholder);
    }

    private function containsText(mixed $value, string $needle): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->containsText($item, $needle)) {
                    return true;
                }
            }

            return false;
        }

        return is_string($value) && str_contains($value, $needle);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function textOf(array $data, string $field): ?string
    {
        $value = $data[$field] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
