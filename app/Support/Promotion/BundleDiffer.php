<?php

declare(strict_types=1);

namespace App\Support\Promotion;

use App\DTOs\ConfigBundle\BundleArtifact;
use App\DTOs\ConfigBundle\ConfigBundle;
use App\DTOs\Promotion\ArtifactDiff;
use App\DTOs\Promotion\BundleDiff;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\Promotion\DiffState;
use JsonException;

class BundleDiffer
{
    public function __construct(
        private readonly PromotionBaselineStore $baselines,
    ) {}

    /**
     * @throws JsonException
     */
    public function diff(ConfigBundle $source, ConfigBundle $target, string $counterpartKey): BundleDiff
    {
        $sourceArtifacts = $this->indexOf($source);
        $targetArtifacts = $this->indexOf($target);

        $sourceHashes = $this->hashesOf($sourceArtifacts);
        $targetHashes = $this->hashesOf($targetArtifacts);
        $baselineHashes = $this->baselines->hashesFor($counterpartKey);

        $diffs = [];

        foreach ($sourceArtifacts + $targetArtifacts as $identifier => $artifact) {
            $sourceHash = $sourceHashes[$identifier] ?? null;
            $targetHash = $targetHashes[$identifier] ?? null;
            $baselineHash = $baselineHashes[$identifier] ?? null;

            $state = $this->stateFor($sourceHash, $targetHash, $baselineHash);

            $sourceArtifact = $sourceArtifacts[$identifier] ?? null;
            $targetArtifact = $targetArtifacts[$identifier] ?? null;

            $comparesPayloads = $sourceArtifact !== null
                && $targetArtifact !== null
                && ($state === DiffState::Modified || $state === DiffState::Conflicted);

            $diffs[] = new ArtifactDiff(
                kind: $artifact->kind,
                key: $artifact->key,
                state: $state,
                sourceHash: $sourceHash,
                targetHash: $targetHash,
                baselineHash: $baselineHash,
                changedPaths: $comparesPayloads
                    ? $this->changedPathsBetween($sourceArtifact->payload, $targetArtifact->payload)
                    : [],
            );
        }

        return new BundleDiff($this->ordered($diffs));
    }

    /**
     * @return array<string, BundleArtifact>
     */
    private function indexOf(ConfigBundle $bundle): array
    {
        $indexed = [];

        foreach ($bundle->artifacts as $artifact) {
            $indexed[$artifact->kind->identifierFor($artifact->key)] = $artifact;
        }

        return $indexed;
    }

    /**
     * @param  array<string, BundleArtifact>  $artifacts
     * @return array<string, string>
     *
     * @throws JsonException
     */
    private function hashesOf(array $artifacts): array
    {
        $hashes = [];

        foreach ($artifacts as $identifier => $artifact) {
            $hashes[$identifier] = $artifact->hash();
        }

        return $hashes;
    }

    private function stateFor(?string $sourceHash, ?string $targetHash, ?string $baselineHash): DiffState
    {
        if ($sourceHash === null) {
            return $baselineHash === null ? DiffState::Unchanged : DiffState::Removed;
        }

        if ($targetHash === null) {
            return DiffState::Added;
        }

        if ($sourceHash === $targetHash) {
            return DiffState::Unchanged;
        }

        if ($baselineHash !== null && $targetHash === $baselineHash) {
            return DiffState::Modified;
        }

        return DiffState::Conflicted;
    }

    /**
     * @param  array<array-key, mixed>  $source
     * @param  array<array-key, mixed>  $target
     * @return list<string>
     */
    private function changedPathsBetween(array $source, array $target): array
    {
        $paths = $this->changedPathsUnder($source, $target, '');

        usort($paths, static fn (string $left, string $right): int => strcmp($left, $right));

        return $paths;
    }

    /**
     * @param  array<array-key, mixed>  $source
     * @param  array<array-key, mixed>  $target
     * @return list<string>
     */
    private function changedPathsUnder(array $source, array $target, string $path): array
    {
        $paths = [];

        foreach (array_keys($source + $target) as $key) {
            $child = is_int($key) ? "{$path}[{$key}]" : ($path === '' ? $key : "{$path}.{$key}");

            if (!array_key_exists($key, $source) || !array_key_exists($key, $target)) {
                $paths[] = $child;

                continue;
            }

            $sourceValue = $source[$key];
            $targetValue = $target[$key];

            if (is_array($sourceValue) && is_array($targetValue)) {
                $paths = [...$paths, ...$this->changedPathsUnder($sourceValue, $targetValue, $child)];

                continue;
            }

            if ($sourceValue !== $targetValue) {
                $paths[] = $child;
            }
        }

        return $paths;
    }

    /**
     * @param  list<ArtifactDiff>  $diffs
     * @return list<ArtifactDiff>
     */
    private function ordered(array $diffs): array
    {
        $ranks = $this->kindRanks();

        usort($diffs, static function (ArtifactDiff $left, ArtifactDiff $right) use ($ranks): int {
            $byKind = $ranks[$left->kind->value] <=> $ranks[$right->kind->value];

            return $byKind !== 0 ? $byKind : strcmp($left->key, $right->key);
        });

        return $diffs;
    }

    /**
     * @return array<string, int>
     */
    private function kindRanks(): array
    {
        $ranks = [];

        foreach (ArtifactKind::cases() as $rank => $kind) {
            $ranks[$kind->value] = $rank;
        }

        return $ranks;
    }
}
