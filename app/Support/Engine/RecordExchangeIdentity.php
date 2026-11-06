<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Models\CustomRecord;
use App\Models\ObjectType;
use Illuminate\Database\Eloquent\Model;

class RecordExchangeIdentity
{
    public function __construct(private readonly RecordEndpointResolver $endpoints) {}

    public function of(Model $record): ?string
    {
        if (!$record instanceof CustomRecord) {
            return (string) $record->getKey();
        }

        $reference = $record->external_reference_id;

        return is_string($reference) && $reference !== '' ? $reference : null;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, Model>
     */
    public function rowsByKey(ObjectType|string $type, array $keys, string $tenantId): array
    {
        if ($keys === []) {
            return [];
        }

        $query = $this->endpoints->newQuery($type);

        if ($query->getModel() instanceof CustomRecord) {
            $query = CustomRecord::query()
                ->where('tenant_id', $tenantId)
                ->ofType($type)
                ->select(['id', 'record_number', 'external_reference_id', 'data']);
        }

        /** @var array<string, Model> $rows */
        $rows = $query->whereKey($keys)
            ->get()
            ->keyBy(static fn (Model $row): string => (string) $row->getKey())
            ->all();

        return $rows;
    }

    public function resolve(ObjectType|string $type, string $identity, string $tenantId): ?Model
    {
        $query = $this->endpoints->newQuery($type);

        if (!$query->getModel() instanceof CustomRecord) {
            return $query->whereKey($identity)->first();
        }

        return CustomRecord::query()
            ->where('tenant_id', $tenantId)
            ->ofType($type)
            ->where('external_reference_id', $identity)
            ->first();
    }
}
