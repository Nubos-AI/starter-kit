<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\DTOs\ConfigBundle\ArtifactWriteResult;
use App\DTOs\ConfigBundle\BundleArtifact;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\ConfigBundle\ArtifactWriteAction;
use App\Enums\Promotion\DiffState;
use App\Models\User;

trait SkipsArtifactWrites
{
    public function apply(User $actingUser, ArtifactKind $kind, BundleArtifact $artifact, DiffState $state): ArtifactWriteResult
    {
        return new ArtifactWriteResult($kind, $artifact->key, ArtifactWriteAction::Skipped, null);
    }

    public function remove(User $actingUser, ArtifactKind $kind, string $key): ArtifactWriteResult
    {
        return new ArtifactWriteResult($kind, $key, ArtifactWriteAction::Removed, null);
    }

    /**
     * @return list<ArtifactWriteResult>
     */
    public function flush(User $actingUser): array
    {
        return [];
    }
}
