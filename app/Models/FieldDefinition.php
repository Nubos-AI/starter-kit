<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomFields\FieldType;
use App\Enums\Engine\MergeFieldStrategy;
use App\Enums\Engine\RollupScope;
use App\Enums\Formulas\FormulaValueType;
use App\Scopes\TenantScope;
use App\Traits\Modules\HasModuleAttributes;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\FieldDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $object_type_id
 * @property string|null $field_group_id
 * @property string $key
 * @property FieldType $field_type
 * @property bool $is_required
 * @property bool $is_unique
 * @property bool $is_searchable
 * @property bool $is_translatable
 * @property bool $is_encrypted
 * @property bool $is_sortable
 * @property bool $is_filterable
 * @property bool $is_default_column
 * @property bool $is_card_field
 * @property int|null $list_position
 * @property MergeFieldStrategy|null $merge_strategy
 * @property array<string, mixed>|null $config
 * @property array<int|string, mixed>|null $validation_rules
 * @property array<string, mixed>|null $default_value
 * @property array<string, mixed>|null $i18n_labels
 * @property array<string, mixed>|null $i18n_descriptions
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[ScopedBy([TenantScope::class])]
#[Fillable(
    [
        'tenant_id',
        'object_type_id',
        'field_group_id',
        'key',
        'field_type',
        'is_required',
        'is_unique',
        'is_searchable',
        'is_translatable',
        'is_encrypted',
        'is_sortable',
        'is_filterable',
        'is_default_column',
        'list_position',
        'merge_strategy',
        'config',
        'validation_rules',
        'default_value',
        'i18n_labels',
        'i18n_descriptions',
    ]
)]
class FieldDefinition extends Model
{
    /** @use HasFactory<FieldDefinitionFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;
    use HasModuleAttributes;
    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'field_type' => FieldType::class,
            'is_required' => 'boolean',
            'is_unique' => 'boolean',
            'is_searchable' => 'boolean',
            'is_translatable' => 'boolean',
            'is_encrypted' => 'boolean',
            'is_sortable' => 'boolean',
            'is_filterable' => 'boolean',
            'is_default_column' => 'boolean',
            'list_position' => 'integer',
            'merge_strategy' => MergeFieldStrategy::class,
            'config' => 'array',
            'validation_rules' => 'array',
            'default_value' => 'array',
            'i18n_labels' => 'array',
            'i18n_descriptions' => 'array',
        ];
    }

    public function resultType(): ?FormulaValueType
    {
        $resultType = $this->config['result_type'] ?? null;

        return is_string($resultType) ? FormulaValueType::tryFrom($resultType) : null;
    }

    public function rollupScope(): RollupScope
    {
        $scope = $this->config['scope'] ?? null;

        return (is_string($scope) ? RollupScope::tryFrom($scope) : null) ?? RollupScope::DirectChildren;
    }

    public function usesNumericIndex(): bool
    {
        return $this->field_type === FieldType::Computed
            ? $this->resultType() === FormulaValueType::Number
            : $this->field_type->isNumericIndex();
    }

    public function isUsableDateField(): bool
    {
        return ($this->field_type === FieldType::Date || $this->field_type === FieldType::DateTime)
            && !$this->is_encrypted
            && !$this->is_translatable;
    }

    /**
     * @return BelongsTo<ObjectType, $this>
     */
    public function objectType(): BelongsTo
    {
        return $this->belongsTo(ObjectType::class);
    }

    /**
     * @return BelongsTo<FieldGroup, $this>
     */
    public function fieldGroup(): BelongsTo
    {
        return $this->belongsTo(FieldGroup::class);
    }
}
