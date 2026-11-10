<?php

declare(strict_types=1);

namespace App\DTOs\Engine;

use Illuminate\Database\Eloquent\Model;

readonly class BackingRelationDescriptor
{
    /**
     * @param  class-string<Model>|null  $relatedModelClass
     */
    public function __construct(
        public string $name,
        public bool $isMany,
        public ?string $counterpartObjectTypeId,
        public ?string $relatedModelClass,
    ) {}
}
