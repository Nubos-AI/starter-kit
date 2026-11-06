<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Contracts\ConfigBundle\ArtifactWriterInterface;
use App\Enums\ConfigBundle\ArtifactKind;

class RolesArtifactWriter implements ArtifactWriterInterface
{
    use SkipsArtifactWrites;

    public function supports(ArtifactKind $kind): bool
    {
        return $kind === ArtifactKind::Roles;
    }
}
