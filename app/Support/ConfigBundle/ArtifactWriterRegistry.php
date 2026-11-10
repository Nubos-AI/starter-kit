<?php

declare(strict_types=1);

namespace App\Support\ConfigBundle;

use App\Contracts\ConfigBundle\ArtifactWriterInterface;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Exceptions\ConfigBundle\UnsupportedArtifactKindException;

class ArtifactWriterRegistry
{
    /**
     * @var list<ArtifactWriterInterface>
     */
    private array $writers;

    /**
     * @param  iterable<ArtifactWriterInterface>  $writers
     */
    public function __construct(iterable $writers = [])
    {
        $this->writers = array_values([...$writers]);
    }

    /**
     * @throws UnsupportedArtifactKindException
     */
    public function for(ArtifactKind $kind): ArtifactWriterInterface
    {
        foreach ($this->writers as $writer) {
            if ($writer->supports($kind)) {
                return $writer;
            }
        }

        throw new UnsupportedArtifactKindException($kind);
    }

    /**
     * @return list<ArtifactWriterInterface>
     */
    public function all(): array
    {
        return $this->writers;
    }
}
