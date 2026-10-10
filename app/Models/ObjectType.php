<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\Engine\CreateRecordAction;
use App\Enums\Engine\StorageStrategy;
use App\Enums\Ui\NavIcon;
use App\Policies\Engine\ObjectTypePolicy;
use App\Scopes\TenantScope;
use App\Support\Engine\ObjectTypeBackingRegistry;
use App\Support\Engine\ObjectTypeRegistry;
use App\Traits\Modules\HasModuleAttributes;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\ObjectTypeFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $hierarchy_relationship_type_id
 * @property string $key
 * @property string $slug
 * @property string|null $business_key_prefix
 * @property bool $is_system
 * @property bool $requires_deletion_reason
 * @property bool $is_navigable
 * @property StorageStrategy $storage_strategy
 * @property string $name
 * @property NavIcon $nav_icon
 * @property int $nav_position
 * @property string|null $record_number_format
 * @property int|null $retention_days
 * @property array<int, mixed>|null $dedup_keys
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[ScopedBy([TenantScope::class])]
#[UsePolicy(ObjectTypePolicy::class)]
#[Fillable(['tenant_id', 'hierarchy_relationship_type_id', 'key', 'slug', 'business_key_prefix', 'is_system', 'requires_deletion_reason', 'is_navigable', 'storage_strategy', 'name', 'nav_icon', 'nav_position', 'record_number_format', 'retention_days', 'dedup_keys'])]
class ObjectType extends Model
{
    /** @use HasFactory<ObjectTypeFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasModuleAttributes;
    use HasUlids;
    use SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = [
        'requires_deletion_reason' => false,
        'is_navigable' => true,
        'storage_strategy' => StorageStrategy::Generic->value,
        'nav_icon' => 'database',
        'nav_position' => 0,
    ];

    protected static function booted(): void
    {
        static::updating(function (ObjectType $type): void {
            self::guardSystemType($type);

            if ($type->isDirty('slug') && $type->getOriginal('slug') !== null) {
                throw new AuthorizationException(__('i18n.backend.models.object_type.the_slug_of_an_object_type_is_immutable_once'));
            }
        });

        static::deleting(function (ObjectType $type): void {
            self::guardSystemType($type);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'requires_deletion_reason' => 'boolean',
            'is_navigable' => 'boolean',
            'nav_icon' => NavIcon::class,
            'nav_position' => 'integer',
            'retention_days' => 'integer',
            'storage_strategy' => StorageStrategy::class,
            'dedup_keys' => 'array',
        ];
    }

    /**
     * @param  Builder<ObjectType>  $query
     */
    #[Scope]
    protected function generic(Builder $query): void
    {
        $query->where('storage_strategy', StorageStrategy::Generic->value);
    }

    /**
     * @return HasMany<CustomRecord, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(CustomRecord::class);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $attributes
     *
     * @throws Throwable
     */
    public function createRecord(array $values, array $attributes = []): CustomRecord
    {
        return app(CreateRecordAction::class)->execute([
            ...$attributes,
            'object_type_id' => $this->getKey(),
            'data' => $values,
        ]);
    }

    /**
     * @return HasMany<FieldDefinition, $this>
     */
    public function fieldDefinitions(): HasMany
    {
        return $this->hasMany(FieldDefinition::class);
    }

    /**
     * @return HasMany<FieldGroup, $this>
     */
    public function fieldGroups(): HasMany
    {
        return $this->hasMany(FieldGroup::class);
    }

    /**
     * @return HasMany<ObjectTypeDefinitionVersion, $this>
     */
    public function definitionVersions(): HasMany
    {
        return $this->hasMany(ObjectTypeDefinitionVersion::class);
    }

    /**
     * @return BelongsTo<RelationshipType, $this>
     */
    public function hierarchyRelationshipType(): BelongsTo
    {
        return $this->belongsTo(RelationshipType::class, 'hierarchy_relationship_type_id');
    }

    public function isGeneric(): bool
    {
        return $this->storage_strategy === StorageStrategy::Generic;
    }

    public function isNative(): bool
    {
        return $this->storage_strategy === StorageStrategy::Native;
    }

    public function hasHierarchy(): bool
    {
        return $this->hierarchy_relationship_type_id !== null;
    }

    public function hasRecords(): bool
    {
        return app(ObjectTypeBackingRegistry::class)->for($this)->hasRows($this);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return Collection<int, CustomRecord>
     */
    public function findDuplicates(array $values, string $tenantId): Collection
    {
        $groups = $this->dedup_keys ?? [];

        if ($groups === []) {
            return new Collection;
        }

        $applicableGroups = [];

        foreach ($groups as $group) {
            $keys = $this->dedupGroupKeys((array) $group);

            if ($keys === null || !$this->coversEveryKey($keys, $values)) {
                continue;
            }

            $applicableGroups[] = $keys;
        }

        if ($applicableGroups === []) {
            return new Collection;
        }

        return CustomRecord::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->ofType($this)
            ->whereNull('deleted_at')
            ->where(function (Builder $query) use ($applicableGroups, $values): void {
                foreach ($applicableGroups as $keys) {
                    $query->orWhere(function (Builder $group) use ($keys, $values): void {
                        foreach ($keys as $key) {
                            $group->whereField($key, $values[$key]);
                        }
                    });
                }
            })
            ->get();
    }

    /**
     * @param  array<array-key, mixed>  $group
     * @return list<string>|null
     */
    private function dedupGroupKeys(array $group): ?array
    {
        $registry = app(ObjectTypeRegistry::class);
        $keys = [];

        foreach ($group as $key) {
            if (!is_string($key) || !$registry->field((string) $this->getKey(), $key) instanceof FieldDefinition) {
                return null;
            }

            $keys[] = $key;
        }

        return $keys === [] ? null : $keys;
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $values
     */
    private function coversEveryKey(array $keys, array $values): bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $values) || $values[$key] === null) {
                return false;
            }
        }

        return true;
    }

    private static function guardSystemType(ObjectType $type): void
    {
        if ($type->getOriginal('is_system')) {
            throw new AuthorizationException(__('i18n.backend.models.object_type.system_object_types_cannot_be_modified_or_deleted'));
        }
    }
}
