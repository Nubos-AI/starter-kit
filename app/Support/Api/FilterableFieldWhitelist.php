<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Models\FieldDefinition;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\ObjectTypeFieldLookup;

class FilterableFieldWhitelist
{
    /**
     * @var list<string>
     */
    public static array $spineAttributes = [
        'recordNumber',
        'externalReferenceId',
        'version',
        'data',
        'createdAt',
        'updatedAt',
    ];

    /**
     * @var array<string, string>
     */
    private array $spineAliases = [
        'recordNumber' => 'record_number',
        'externalReferenceId' => 'external_reference_id',
        'version' => 'version',
        'createdAt' => 'created_at',
        'updatedAt' => 'updated_at',
    ];

    /**
     * @var list<string>
     */
    private array $includePaths = ['objectType'];

    public function __construct(private readonly ObjectTypeFieldLookup $fieldLookup)
    {
        $this->spineAliases += config('modules.records.api_aliases', []);
        $this->includePaths = [...$this->includePaths, ...array_map(strval(...), array_keys(config('modules.records.api_relationships', [])))];
    }

    /**
     * @return list<string>
     */
    public function filterableKeys(?User $user, string $objectTypeId): array
    {
        return array_merge($this->spineKeys(), $this->customKeys($user, $objectTypeId, 'is_filterable'));
    }

    /**
     * @return list<string>
     */
    public function sortableKeys(?User $user, string $objectTypeId): array
    {
        return array_merge($this->spineKeys(), $this->customKeys($user, $objectTypeId, 'is_sortable'));
    }

    /**
     * @return list<string>
     */
    public function attributeKeys(?User $user, string $objectTypeId): array
    {
        return array_values(array_unique(array_merge(
            self::$spineAttributes,
            $this->customKeys($user, $objectTypeId),
        )));
    }

    /**
     * @return list<string>
     */
    public function includePaths(): array
    {
        return $this->includePaths;
    }

    /**
     * @return list<string>
     */
    public static function parseList(string $value): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $entry): bool => $entry !== '',
        ));
    }

    public function spineColumn(string $key): ?string
    {
        if (array_key_exists($key, $this->spineAliases)) {
            return $this->spineAliases[$key];
        }

        return in_array($key, $this->spineAliases, true) ? $key : null;
    }

    /**
     * @return list<string>
     */
    private function spineKeys(): array
    {
        return array_values(array_unique(array_merge(
            array_keys($this->spineAliases),
            array_values($this->spineAliases),
        )));
    }

    /**
     * @return list<string>
     */
    private function customKeys(?User $user, string $objectTypeId, ?string $flag = null): array
    {
        if ($user === null) {
            return [];
        }

        $fields = $this->fieldLookup->fields($objectTypeId);

        if ($flag !== null) {
            $fields = $fields->filter(
                static fn (FieldDefinition $field): bool => (bool) $field->getAttribute($flag) && !$field->is_encrypted,
            );
        }

        /** @var list<string> $defined */
        $defined = $fields->pluck('key')->all();

        $readable = FieldVisibilityResolver::forRequest()->readableFieldKeys($user, $objectTypeId);

        return array_values(array_filter(
            array_intersect($defined, $readable),
            static fn (string $key): bool => preg_match('/^[a-zA-Z0-9_]+$/', $key) === 1,
        ));
    }
}
