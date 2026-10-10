<?php

declare(strict_types=1);

namespace App\Support\Promotion;

use App\Contracts\ConfigBundle\ArtifactRefusalInterface;
use App\DTOs\Promotion\PromotionComparison;
use App\DTOs\Promotion\PromotionSelection;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\Promotion\DiffState;
use App\Models\PromotionRun;
use App\Support\ConfigBundle\ArtifactWriterRegistry;
use App\Support\Tenancy\TenantContext;

class PromotionTargetRefusals
{
    public function __construct(private readonly ArtifactWriterRegistry $writers) {}

    /**
     * @return list<array{kind: ArtifactKind, key: string, reason: string, overwrite_consequence: string|null}>
     */
    public function of(PromotionRun $run, PromotionComparison $comparison): array
    {
        /** @var list<array{kind: ArtifactKind, key: string, reason: string, overwrite_consequence: string|null}> */
        return TenantContext::withTenantId($run->tenant_id, function () use ($comparison): array {
            $refusals = [];

            foreach ($comparison->diff->diffs as $entry) {
                if ($entry->state === DiffState::Unchanged || $entry->state === DiffState::Removed) {
                    continue;
                }

                $artifact = $comparison->source->find($entry->kind, $entry->key);
                $writer = $this->refusingWriterFor($entry->kind);

                if ($artifact === null || $writer === null) {
                    continue;
                }

                $state = $entry->state === DiffState::Conflicted ? DiffState::Modified : $entry->state;
                $refusal = $writer->refusalFor($entry->kind, $artifact, $state);

                if ($refusal !== null) {
                    $refusals[] = [
                        'kind' => $entry->kind,
                        'key' => $entry->key,
                        'reason' => $refusal->reason,
                        'overwrite_consequence' => $refusal->overwriteConsequence,
                    ];
                }
            }

            return $refusals;
        });
    }

    /**
     * @param  list<array{kind: ArtifactKind, key: string, reason: string, overwrite_consequence: string|null}>  $refusals
     * @return list<array{kind: ArtifactKind, key: string, reason: string, overwrite_consequence: string|null}>
     */
    public function blocking(array $refusals, PromotionSelection $overwrites): array
    {
        return array_values(array_filter(
            $refusals,
            static fn (array $refusal): bool => $refusal['overwrite_consequence'] === null
                || !$overwrites->contains($refusal['kind'], $refusal['key']),
        ));
    }

    private function refusingWriterFor(ArtifactKind $kind): ?ArtifactRefusalInterface
    {
        foreach ($this->writers->all() as $writer) {
            if ($writer->supports($kind)) {
                return $writer instanceof ArtifactRefusalInterface ? $writer : null;
            }
        }

        return null;
    }
}
