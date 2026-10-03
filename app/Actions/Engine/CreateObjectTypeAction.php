<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Enums\Authorization\RoleScope;
use App\Enums\CustomFields\FieldType;
use App\Enums\CustomFields\ReservedFieldKey;
use App\Enums\Engine\StorageStrategy;
use App\Exceptions\Engine\ReservedSlugException;
use App\Models\ObjectType;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Authorization\PermissionCatalog;
use App\Support\Engine\BusinessKeyRules;
use App\Support\Engine\RecordNumberFormatter;
use App\Support\Engine\SlugGenerator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\NotIn;
use Illuminate\Validation\Rules\Unique;
use Throwable;

class CreateObjectTypeAction
{
    private string $slugPattern = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public function __construct(
        private readonly SlugGenerator $slugGenerator,
        private readonly PermissionCatalog $permissionCatalog,
        private readonly BusinessKeyRules $businessKeyRules,
        private readonly CreateFieldDefinitionAction $createFieldDefinition,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws Throwable
     */
    public function execute(
        array $input,
        bool $isSystem = false,
        StorageStrategy $storageStrategy = StorageStrategy::Generic,
        ?string $slug = null,
        bool $defaultsRecordNumberFormat = true,
        bool $claimsReservedSlug = false,
    ): ObjectType {
        $validated = Validator::make(
            [...$input, 'slug' => $slug],
            [
                'key' => ['required', 'string', 'max:255'],
                'name' => ['required', 'string', 'max:255'],
                'slug' => $this->slugRules($claimsReservedSlug),
                ...$this->businessKeyRules->all(),
                ...config('modules.object_types.create_rules', []),
            ]
        )->validate();

        $recordNumberFormat = $validated['record_number_format'] ?? null;

        if ($defaultsRecordNumberFormat) {
            $recordNumberFormat ??= RecordNumberFormatter::$defaultFormat;
        }

        return DB::transaction(function () use ($validated, $isSystem, $storageStrategy, $recordNumberFormat): ObjectType {
            $objectType = ObjectType::query()->create([
                'key' => $validated['key'],
                'slug' => $validated['slug'] ?? $this->slugGenerator->generate($validated['name']),
                'name' => $validated['name'],
                'is_system' => $isSystem,
                'storage_strategy' => $storageStrategy,
                'business_key_prefix' => $validated['business_key_prefix'],
                'record_number_format' => $recordNumberFormat,
                ...Arr::only($validated, config('modules.object_types.attributes', [])),
            ]);

            $this->seedPermissions($objectType, $isSystem);
            $this->grantToAuthoringRoles($objectType);
            $this->seedReservedFields($objectType, $isSystem);

            return $objectType;
        });
    }

    /**
     * @return array<int, NotIn|Unique|string>
     */
    private function slugRules(bool $claimsReservedSlug): array
    {
        /** @var list<string> $reserved */
        $reserved = $claimsReservedSlug ? [] : config('engine.reserved_slugs', []);

        return [
            'nullable',
            'string',
            'max:255',
            "regex:{$this->slugPattern}",
            Rule::notIn($reserved),
            Rule::unique('object_types', 'slug')->where('tenant_id', TenantContext::currentId()),
        ];
    }

    private function grantToAuthoringRoles(ObjectType $objectType): void
    {
        $permissionIds = Permission::query()
            ->whereIn('name', $this->permissionCatalog->namesForObjectType($objectType))
            ->pluck('id');

        $roles = Role::query()
            ->whereHas(
                'permissions',
                fn (Builder $query): Builder => $query->where('name', 'object-types.create'),
            )
            ->get();

        foreach ($roles as $role) {
            $role->permissions()->syncWithoutDetaching($permissionIds);
        }
    }

    /**
     * @throws Throwable
     */
    private function seedReservedFields(ObjectType $objectType, bool $isSystem): void
    {
        if ($isSystem) {
            return;
        }

        $this->createFieldDefinition->execute([
            'object_type_id' => $objectType->getKey(),
            'key' => ReservedFieldKey::Name->value,
            'field_type' => FieldType::TextShort->value,
            'is_required' => true,
            'is_searchable' => true,
            'is_sortable' => true,
            'is_filterable' => true,
            'is_default_column' => true,
            'list_position' => 0,
            'i18n_labels' => [$this->fallbackLocale() => __('i18n.backend.actions.engine.create_object_type_action.name')],
        ]);
    }

    private function fallbackLocale(): string
    {
        $locale = config('app.fallback_locale');

        return is_string($locale) ? $locale : 'en';
    }

    private function seedPermissions(ObjectType $objectType, bool $isSystem): void
    {
        foreach ($this->permissionCatalog->namesForObjectType($objectType) as $name) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $name, 'scope' => RoleScope::Tenant],
                ['group' => $objectType->slug, 'is_system' => $isSystem],
            );

            if (!$permission->wasRecentlyCreated || $permission->group !== $objectType->slug) {
                throw ReservedSlugException::forPermissionName($objectType->slug, $name);
            }
        }
    }
}
