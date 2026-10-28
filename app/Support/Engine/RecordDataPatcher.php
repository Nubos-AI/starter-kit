<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Models\CustomRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JsonException;

class RecordDataPatcher
{
    private int $pairsPerJsonObject = 50;

    private string $objectBase = "(case when jsonb_typeof(custom_records.data) = 'object' then custom_records.data else '{}'::jsonb end)";

    /**
     * @param  array<string, array{sql: string, bindings: list<mixed>}>  $expressions
     *
     * @throws JsonException
     */
    public function merge(CustomRecord $record, array $expressions): bool
    {
        if ($expressions === []) {
            return false;
        }

        $pairs = collect($expressions)->map(static fn (array $expression, int|string $key): array => [
            'sql' => "?::text, {$expression['sql']}",
            'bindings' => [(string) $key, ...$expression['bindings']],
        ]);

        $objects = $pairs
            ->chunk($this->pairsPerJsonObject)
            ->map(static fn (Collection $chunk): string => 'jsonb_build_object('.$chunk->pluck('sql')->implode(', ').')')
            ->implode(' || ');

        $merged = "{$this->objectBase} || merged_values.data";

        $rows = DB::selectFromWriteConnection(
            "with merged_values as (select {$objects} as data) "
            ."update custom_records set data = {$merged} from merged_values "
            .'where custom_records.id = ? and custom_records.tenant_id = ? '
            ."and custom_records.data is distinct from ({$merged}) "
            .'returning custom_records.data',
            [
                ...$pairs->pluck('bindings')->flatten(1)->all(),
                (string) $record->getKey(),
                (string) $record->getAttribute('tenant_id'),
            ],
        );

        return $this->adopt($record, $rows);
    }

    /**
     * @param  array<string, mixed>  $values
     *
     * @throws JsonException
     */
    public function overwrite(CustomRecord $record, array $values): bool
    {
        $rows = DB::selectFromWriteConnection(
            "update custom_records set data = {$this->objectBase} || ?::jsonb "
            .'where custom_records.id = ? and custom_records.tenant_id = ? '
            .'returning custom_records.data',
            [
                json_encode($values, JSON_THROW_ON_ERROR),
                (string) $record->getKey(),
                (string) $record->getAttribute('tenant_id'),
            ],
        );

        return $this->adopt($record, $rows);
    }

    /**
     * @param  array<int, mixed>  $rows
     *
     * @throws JsonException
     */
    private function adopt(CustomRecord $record, array $rows): bool
    {
        $written = data_get($rows, '0.data');

        if (!is_string($written)) {
            return false;
        }

        $data = json_decode($written, true, 512, JSON_THROW_ON_ERROR);

        $record->setAttribute('data', is_array($data) ? $data : []);
        $record->syncOriginalAttribute('data');

        return true;
    }
}
