<?php

declare(strict_types=1);

namespace App\Contracts\Links;

interface SourceLinkableInterface
{
    public function linkedRecordId(): ?string;

    public function linkedSourceType(): ?string;

    public function linkedSourceId(): ?string;
}
