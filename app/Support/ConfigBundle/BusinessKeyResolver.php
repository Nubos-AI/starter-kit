<?php

declare(strict_types=1);

namespace App\Support\ConfigBundle;

use BackedEnum;

class BusinessKeyResolver
{
    /**
     * @var list<string>
     */
    private array $supportTables;

    /**
     * @var array<string, array<string, string>>
     */
    private array $keys = [];

    /**
     * @var array<string, string>
     */
    private array $resolvable = [];

    /**
     * @param  array<string, list<array<string, mixed>>>  $rowsByTable
     */
    public function __construct(private readonly array $rowsByTable)
    {
        $supportTables = config('engine.config_bundle.key_source_tables');
        $this->supportTables = array_values(array_map(
            static fn (mixed $table): string => (string) $table,
            is_array($supportTables) ? $supportTables : [],
        ));

        foreach (array_keys($this->rowsByTable) as $table) {
            $this->keys[$table] = $table === 'dashboard_widgets'
                ? $this->buildWidgetKeys()
                : $this->buildKeys($table);

            if (in_array($table, $this->supportTables, true)) {
                continue;
            }

            $this->resolvable += $this->keys[$table];
        }
    }

    public function keyFor(string $table, ?string $id): ?string
    {
        if ($id === null) {
            return null;
        }

        return $this->keys[$table][$id] ?? null;
    }

    public function resolve(string $ulid): ?string
    {
        return $this->resolvable[$ulid] ?? null;
    }

    public function rolePermissionKey(?string $roleId, ?string $permissionId): ?string
    {
        $role = $this->keyFor('roles', $roleId);
        $permission = $this->keyFor('permissions', $permissionId);

        if ($role === null || $permission === null) {
            return null;
        }

        return "{$role}:{$permission}";
    }

    /**
     * @return list<string>
     */
    public function relatedKeys(string $pivot, string $ownerColumn, string $relatedTable, string $relatedColumn, ?string $ownerId): array
    {
        if ($ownerId === null) {
            return [];
        }

        $keys = [];

        foreach ($this->rowsByTable[$pivot] ?? [] as $row) {
            $team = $this->text($row[$ownerColumn] ?? null) === $ownerId
                ? $this->keyFor($relatedTable, $this->text($row[$relatedColumn] ?? null))
                : null;

            if ($team !== null) {
                $keys[] = $team;
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /**
     * @return array<string, string>
     */
    private function buildKeys(string $table): array
    {
        $keys = [];

        foreach ($this->rowsByTable[$table] ?? [] as $row) {
            $id = $this->text($row['id'] ?? null);
            $key = $this->keyOf($table, $row);

            if ($id === null || $key === null) {
                continue;
            }

            $keys[$id] = $key;
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function keyOf(string $table, array $row): ?string
    {
        return match ($table) {
            'object_types' => $this->text($row['key'] ?? null),
            'relationship_types' => $this->text($row['key'] ?? null),
            'reminder_types', 'skills', 'webhook_subscriptions' => $this->text($row['name'] ?? null),
            'permissions', 'roles' => $this->join([$row['scope'] ?? null, $row['name'] ?? null]),
            'teams' => $this->text($row['slug'] ?? null),
            'field_groups', 'field_definitions' => $this->qualified('object_types', $row['object_type_id'] ?? null, [$row['key'] ?? null]),
            'field_dependencies' => $this->dependencyKey($row),
            'field_permissions' => $this->pairKey('roles', $row['role_id'] ?? null, 'field_definitions', $row['field_definition_id'] ?? null),
            'team_record_access_rules' => $this->pairKey('teams', $row['team_id'] ?? null, 'object_types', $row['object_type_id'] ?? null),
            'notification_type_defaults' => $this->join([$row['type'] ?? null, $row['channel'] ?? null]),
            'dashboards' => ($row['is_tenant_wide'] ?? false) === true ? $this->text($row['name'] ?? null) : null,
            'goals' => $this->qualified('reports', $row['report_id'] ?? null, [$row['name'] ?? null]),
            'notification_rules' => $this->qualified('object_types', $row['object_type_id'] ?? null, [$row['trigger_type'] ?? null, $row['name'] ?? null]),
            'automation_templates', 'segments' => $this->optionallyQualified('object_types', $row['object_type_id'] ?? null, $row['name'] ?? null),
            default => $this->qualified('object_types', $row['object_type_id'] ?? null, [$row['name'] ?? null]),
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function dependencyKey(array $row): ?string
    {
        return $this->pairKey(
            'field_definitions',
            $row['rollup_field_id'] ?? null,
            'field_definitions',
            $row['depends_on_field_id'] ?? null,
        );
    }

    private function pairKey(string $firstTable, mixed $firstId, string $secondTable, mixed $secondId): ?string
    {
        $first = $this->keyFor($firstTable, $this->text($firstId));
        $second = $this->keyFor($secondTable, $this->text($secondId));

        if ($first === null || $second === null) {
            return null;
        }

        return "{$first}:{$second}";
    }

    /**
     * @param  list<mixed>  $components
     */
    protected function qualified(string $parentTable, mixed $parentId, array $components): ?string
    {
        $parent = $this->keyFor($parentTable, $this->text($parentId));

        if ($parent === null) {
            return null;
        }

        return $this->join([$parent, ...$components]);
    }

    private function optionallyQualified(string $parentTable, mixed $parentId, mixed $component): ?string
    {
        $parent = $this->text($parentId) === null
            ? $this->token('absent_reference')
            : $this->keyFor($parentTable, $this->text($parentId));

        if ($parent === null) {
            return null;
        }

        return $this->join([$parent, $component]);
    }

    /**
     * @return array<string, string>
     */
    private function buildWidgetKeys(): array
    {
        $grouped = [];

        foreach ($this->rowsByTable['dashboard_widgets'] ?? [] as $row) {
            $id = $this->text($row['id'] ?? null);
            $dashboard = $this->keyFor('dashboards', $this->text($row['dashboard_id'] ?? null));

            if ($id === null || $dashboard === null) {
                continue;
            }

            $grouped[$dashboard][] = ['id' => $id, 'order' => $this->widgetOrder($row)];
        }

        $keys = [];

        foreach ($grouped as $dashboard => $widgets) {
            usort($widgets, static fn (array $left, array $right): int => $left['order'] <=> $right['order']);

            foreach ($widgets as $index => $widget) {
                $keys[$widget['id']] = $dashboard.':'.($index + 1);
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function widgetOrder(array $row): array
    {
        $definition = json_encode($row['definition'] ?? null);

        return [
            sprintf('%020d', is_numeric($row['position'] ?? null) ? (int) $row['position'] : 0),
            $this->text($row['title'] ?? null) ?? '',
            $this->text($row['chart_type'] ?? null) ?? '',
            $this->keyFor('reports', $this->text($row['report_id'] ?? null)) ?? '',
            $this->keyFor('goals', $this->text($row['goal_id'] ?? null)) ?? '',
            is_string($definition) ? $definition : '',
        ];
    }

    /**
     * @param  list<mixed>  $components
     */
    protected function join(array $components): ?string
    {
        $texts = [];

        foreach ($components as $component) {
            $text = $this->text($component);

            if ($text === null) {
                return null;
            }

            $texts[] = $text;
        }

        return implode(':', $texts);
    }

    protected function lastComponent(string $key): string
    {
        $components = explode(':', $key);

        return end($components);
    }

    protected function token(string $name): string
    {
        $tokens = config('engine.config_bundle.reserved_tokens');

        return is_array($tokens) && is_string($tokens[$name] ?? null) ? $tokens[$name] : $name;
    }

    protected function text(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
