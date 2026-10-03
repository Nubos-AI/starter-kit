<?php

declare(strict_types=1);

namespace App\Support\Promotion;

use App\DTOs\ConfigBundle\BundleArtifact;
use App\DTOs\ConfigBundle\ConfigBundle;
use App\DTOs\Promotion\BundleDiff;
use App\DTOs\Promotion\RenameHint;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\Promotion\DiffState;
use JsonException;

class RenameDetector
{
    /**
     * @return list<RenameHint>
     *
     * @throws JsonException
     */
    public function detect(BundleDiff $diff, ConfigBundle $source, ConfigBundle $target): array
    {
        $sourceArtifacts = $this->artifactsByIdentifier($source);
        $targetArtifacts = $this->artifactsByIdentifier($target);

        $index = $this->indexRemovedArtifacts($diff, $targetArtifacts);

        $consumed = [];
        $hints = [];

        foreach ($diff->diffs as $entry) {
            if ($entry->state !== DiffState::Added) {
                continue;
            }

            $artifact = $sourceArtifacts[$entry->kind->identifierFor($entry->key)] ?? null;

            if ($artifact === null) {
                continue;
            }

            $hash = $this->residualHashOf($artifact);

            if ($hash === null) {
                continue;
            }

            $candidates = $index[$entry->kind->value][$hash] ?? [];
            $candidate = $this->soleCandidate($candidates, $consumed, $entry->kind);

            if ($candidate === null) {
                continue;
            }

            $consumed[$entry->kind->identifierFor($candidate)] = true;

            $hints[] = new RenameHint(
                kind: $entry->kind,
                fromKey: $candidate,
                toKey: $entry->key,
                exact: true,
            );
        }

        return $hints;
    }

    /**
     * @return array<string, BundleArtifact>
     */
    private function artifactsByIdentifier(ConfigBundle $bundle): array
    {
        $map = [];

        foreach ($bundle->artifacts as $artifact) {
            $map[$artifact->kind->identifierFor($artifact->key)] = $artifact;
        }

        return $map;
    }

    /**
     * @param  array<string, BundleArtifact>  $targetArtifacts
     * @return array<string, array<string, list<string>>>
     *
     * @throws JsonException
     */
    private function indexRemovedArtifacts(BundleDiff $diff, array $targetArtifacts): array
    {
        $index = [];

        foreach ($diff->diffs as $entry) {
            if ($entry->state !== DiffState::Removed) {
                continue;
            }

            $artifact = $targetArtifacts[$entry->kind->identifierFor($entry->key)] ?? null;

            if ($artifact === null) {
                continue;
            }

            $hash = $this->residualHashOf($artifact);

            if ($hash === null) {
                continue;
            }

            $index[$entry->kind->value][$hash][] = $entry->key;
        }

        return $index;
    }

    /**
     * @throws JsonException
     */
    private function residualHashOf(BundleArtifact $artifact): ?string
    {
        $residual = array_diff_key($artifact->payload, ['key' => true]);

        if ($residual === []) {
            return null;
        }

        return (new BundleArtifact(
            kind: $artifact->kind,
            key: $artifact->key,
            payload: $residual,
        ))->hash();
    }

    /**
     * @param  list<string>  $candidates
     * @param  array<string, bool>  $consumed
     */
    private function soleCandidate(array $candidates, array $consumed, ArtifactKind $kind): ?string
    {
        $available = array_values(array_filter(
            $candidates,
            static fn (string $key): bool => !isset($consumed[$kind->identifierFor($key)]),
        ));

        return count($available) === 1 ? $available[0] : null;
    }
}
