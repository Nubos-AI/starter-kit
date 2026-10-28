<?php

declare(strict_types=1);

namespace App\Contracts\ConfigBundle;

use App\DTOs\ConfigBundle\ArtifactRefusal;
use App\DTOs\ConfigBundle\BundleArtifact;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\Promotion\DiffState;

interface ArtifactRefusalInterface
{
    public function refusalFor(ArtifactKind $kind, BundleArtifact $artifact, DiffState $state): ?ArtifactRefusal;

    public function clearForOverwrite(ArtifactKind $kind, BundleArtifact $artifact): void;
}
