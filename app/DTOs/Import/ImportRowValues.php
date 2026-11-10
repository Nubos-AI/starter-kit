<?php

declare(strict_types=1);

namespace App\DTOs\Import;

use App\Models\FieldDefinition;

readonly class ImportRowValues
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array{values: list<string>, field: FieldDefinition}>  $relations
     */
    public function __construct(
        public ?string $externalReferenceId,
        public ?string $recordNumber,
        public array $data,
        public array $relations,
    ) {}
}
