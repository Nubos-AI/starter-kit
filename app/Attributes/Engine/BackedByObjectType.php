<?php

declare(strict_types=1);

namespace App\Attributes\Engine;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class BackedByObjectType
{
    public function __construct(public string $slug) {}
}
