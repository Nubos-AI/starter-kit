<?php

declare(strict_types=1);

namespace App\DTOs\Engine;

use App\Models\FieldDefinition;
use App\Support\Segments\SystemSegmentDescriptor;
use Illuminate\Support\Collection;

readonly class RecordQueryScopeResult
{
    /**
     * @param  Collection<int, FieldDefinition>  $readableFields
     * @param  Collection<int, FieldDefinition>  $gridFields
     * @param  array<string, array{sql: literal-string, bindings: list<mixed>}>  $agingExpressions
     */
    public function __construct(
        public Collection $readableFields,
        public Collection $gridFields,
        public array $agingExpressions,
        public ?SystemSegmentDescriptor $descriptor,
        public RecordTreeOrderResult $hierarchy,
        public bool $searchApplied = false,
    ) {}
}
