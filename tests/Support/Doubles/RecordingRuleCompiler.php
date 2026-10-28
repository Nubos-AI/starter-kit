<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Models\CustomRecord;
use App\Support\Authorization\RowAccess\EffectiveRecordAccessRules;
use App\Support\Authorization\RowAccess\RecordAccessRuleCompiler;
use Illuminate\Database\Eloquent\Builder;
use Tests\Support\QueryShape;

class RecordingRuleCompiler extends RecordAccessRuleCompiler
{
    /**
     * @var list<array{shape: QueryShape, table: string, objectTypeIds: list<string>}>
     */
    public array $calls = [];

    public function __construct() {}

    /**
     * @param  Builder<covariant CustomRecord>  $query
     */
    public function apply(Builder $query, EffectiveRecordAccessRules $rules, string $table): void
    {
        $this->calls[] = [
            'shape' => QueryShape::of($query->getQuery()),
            'table' => $table,
            'objectTypeIds' => $rules->objectTypeIds(),
        ];
    }

    public function forget(): void
    {
        $this->calls = [];
    }
}
