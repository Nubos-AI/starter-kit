<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use Closure;

trait RecordsPresenceLookups
{
    /**
     * @var list<array{table: string, column: string, value: mixed, extra: array<string, mixed>}>
     */
    public array $lookups = [];

    private ?string $owningTenantId = null;

    public function ownedByTenant(string $tenantId): static
    {
        $this->owningTenantId = $tenantId;

        return $this;
    }

    public function wasLookedUpWithin(string $table, string $tenantId): bool
    {
        foreach ($this->lookups as $lookup) {
            if ($lookup['table'] === $table && ($lookup['extra']['tenant_id'] ?? null) === $tenantId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int|string, mixed>  $extra
     */
    private function recordLookup(string $table, string $column, mixed $value, array $extra): bool
    {
        $conditions = array_filter(
            $extra,
            static fn (mixed $condition, int|string $key): bool => is_string($key) && !$condition instanceof Closure,
            ARRAY_FILTER_USE_BOTH,
        );

        $this->lookups[] = ['table' => $table, 'column' => $column, 'value' => $value, 'extra' => $conditions];

        if ($this->owningTenantId === null || !array_key_exists('tenant_id', $conditions)) {
            return true;
        }

        return (string) $conditions['tenant_id'] === $this->owningTenantId;
    }
}
