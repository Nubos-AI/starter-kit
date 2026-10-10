<?php

declare(strict_types=1);

namespace App\Support\ConfigBundle;

use App\DTOs\ConfigBundle\ArtifactWriteResult;
use App\DTOs\ConfigBundle\BundleArtifact;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\ConfigBundle\ArtifactWriteAction;
use App\Enums\Promotion\DiffState;
use App\Models\User;

class ArtifactWriteSequence
{
    /**
     * @param  list<array{kind: ArtifactKind, artifact: BundleArtifact, state: DiffState, rank: int}>  $writes
     * @return list<ArtifactWriteResult>
     */
    public function apply(ArtifactWriterRegistry $writers, User $actingUser, array $writes): array
    {
        $results = [];
        $awaiting = [];

        foreach ($writes as $position => $write) {
            $results[$position] = $writers->for($write['kind'])->apply($actingUser, $write['kind'], $write['artifact'], $write['state']);

            if ($results[$position]->awaitsReference()) {
                $awaiting[$position] = $write;
            }
        }

        while ($awaiting !== []) {
            $settledCount = count($awaiting);

            foreach ($awaiting as $position => $write) {
                $results[$position] = $this->retried($writers, $actingUser, $write, $results[$position]);

                if (!$results[$position]->awaitsReference()) {
                    unset($awaiting[$position]);
                }
            }

            if (count($awaiting) === $settledCount) {
                break;
            }
        }

        return array_values(array_map($this->settled(...), $results));
    }

    /**
     * @param  array{kind: ArtifactKind, artifact: BundleArtifact, state: DiffState, rank: int}  $write
     */
    private function retried(ArtifactWriterRegistry $writers, User $actingUser, array $write, ArtifactWriteResult $earlier): ArtifactWriteResult
    {
        $writer = $writers->for($write['kind']);

        if ($earlier->action === ArtifactWriteAction::Skipped) {
            return $writer->apply($actingUser, $write['kind'], $write['artifact'], $write['state']);
        }

        $retried = $writer->apply($actingUser, $write['kind'], $write['artifact'], DiffState::Modified);

        return new ArtifactWriteResult(
            kind: $earlier->kind,
            key: $earlier->key,
            action: $earlier->action,
            modelId: $earlier->modelId ?? $retried->modelId,
            notes: array_values(array_unique([...$earlier->notes, ...$retried->notes])),
            awaitedReference: $retried->awaitedReference,
        );
    }

    private function settled(ArtifactWriteResult $result): ArtifactWriteResult
    {
        if ($result->awaitedReference === null || in_array($result->awaitedReference, $result->notes, true)) {
            return $result;
        }

        return new ArtifactWriteResult(
            kind: $result->kind,
            key: $result->key,
            action: $result->action,
            modelId: $result->modelId,
            notes: [...$result->notes, $result->awaitedReference],
            awaitedReference: $result->awaitedReference,
        );
    }
}
