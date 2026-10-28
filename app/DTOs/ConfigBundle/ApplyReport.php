<?php

declare(strict_types=1);

namespace App\DTOs\ConfigBundle;

use App\Enums\ConfigBundle\ArtifactKind;

readonly class ApplyReport
{
    /**
     * @param  list<ArtifactWriteResult>  $results
     * @param  list<array{kind: ArtifactKind, key: string, optional: bool}>  $pulledIn
     * @param  list<string>  $notes
     */
    public function __construct(
        public array $results,
        public array $pulledIn,
        public array $notes,
    ) {}

    public function count(): int
    {
        return count($this->results);
    }
}
