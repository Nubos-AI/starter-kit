<?php

declare(strict_types=1);

namespace App\Contracts\ConfigBundle;

use App\DTOs\ConfigBundle\ArtifactWriteResult;
use App\DTOs\ConfigBundle\BundleArtifact;
use App\Enums\Promotion\DiffState;
use App\Models\User;

interface ArtifactWriterInterface
{
    public function supports(string $kind): bool;

    public function apply(User $actingUser, string $kind, BundleArtifact $artifact, DiffState $state): ArtifactWriteResult;

    public function remove(User $actingUser, string $kind, string $key): ArtifactWriteResult;

    /**
     * @return list<ArtifactWriteResult>
     */
    public function flush(User $actingUser): array;
}
