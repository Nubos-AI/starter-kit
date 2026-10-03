<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Enums\Engine\StorageStrategy;
use App\Enums\Ui\NavIcon;
use App\Models\ObjectType;
use App\Models\RelationshipType;
use App\Support\Engine\BusinessKeyRules;
use App\Support\Engine\RecordNumberFormatter;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class UpdateObjectTypeAction
{
    public function __construct(
        private readonly BusinessKeyRules $businessKeyRules,
        private readonly SyncHierarchyCarrierAction $syncHierarchyCarrier,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException|Throwable
     * @throws Throwable
     */
    public function execute(ObjectType $objectType, array $input, ?RelationshipType $hierarchyCarrier = null): ObjectType
    {
        $businessKeyRules = $this->businessKeyRules->all($objectType);
        $businessKeyRules['business_key_prefix'] = ['nullable', ...$businessKeyRules['business_key_prefix']];

        $validated = Validator::make(
            $input,
            [
                'name' => ['required', 'string', 'max:255'],
                ...$businessKeyRules,
                'storage_strategy' => ['sometimes', 'required', Rule::enum(StorageStrategy::class)],
                'dedup_keys' => ['sometimes', 'nullable', 'array', 'list'],
                'hierarchy_enabled' => ['boolean'],
                'is_navigable' => ['boolean'],
                'nav_position' => ['integer', 'min:0', 'max:65535'],
                'nav_icon' => ['string', Rule::enum(NavIcon::class)],
                'requires_deletion_reason' => ['boolean'],
                'retention_days' => ['nullable', 'integer', 'min:1', 'max:36500'],
                ...config('modules.object_types.update_rules', []),
            ]
        )->validate();

        $attributes = [
            'name' => $validated['name'],
            ...Arr::only($validated, config('modules.object_types.attributes', [])),
        ];

        if (array_key_exists('requires_deletion_reason', $validated)) {
            $attributes['requires_deletion_reason'] = (bool) $validated['requires_deletion_reason'];
        }

        if (array_key_exists('is_navigable', $validated)) {
            $attributes['is_navigable'] = (bool) $validated['is_navigable'];
        }

        if (array_key_exists('nav_position', $validated)) {
            $attributes['nav_position'] = (int) $validated['nav_position'];
        }

        if (array_key_exists('nav_icon', $validated)) {
            $attributes['nav_icon'] = $validated['nav_icon'];
        }

        if (array_key_exists('retention_days', $validated)) {
            $attributes['retention_days'] = $validated['retention_days'];
        }

        if (array_key_exists('dedup_keys', $validated)) {
            $this->guardDedupKeys($validated['dedup_keys']);

            $attributes['dedup_keys'] = $validated['dedup_keys'];
        }

        if (array_key_exists('storage_strategy', $validated)) {
            $storageStrategy = $validated['storage_strategy'] instanceof StorageStrategy
                ? $validated['storage_strategy']
                : StorageStrategy::from($validated['storage_strategy']);

            $this->guardStorageStrategyChange($objectType, $storageStrategy);

            $attributes['storage_strategy'] = $storageStrategy;
        }

        if (array_key_exists('business_key_prefix', $validated)) {
            $prefix = $this->textOrNull($validated['business_key_prefix']);
            $format = match (true) {
                array_key_exists('record_number_format', $validated) => $this->textOrNull($validated['record_number_format']),
                $prefix !== null => RecordNumberFormatter::$defaultFormat,
                default => null,
            };

            $this->guardBusinessKeyChange($objectType, $prefix, $format);

            $attributes['business_key_prefix'] = $prefix;
            $attributes['record_number_format'] = $format;
        }

        $hierarchyEnabled = array_key_exists('hierarchy_enabled', $validated)
            ? (bool) $validated['hierarchy_enabled']
            : null;

        return DB::transaction(function () use ($objectType, $attributes, $hierarchyEnabled, $hierarchyCarrier): ObjectType {
            $retiredCarrierId = null;

            if ($hierarchyEnabled === true) {
                $attributes['hierarchy_relationship_type_id'] = $this->syncHierarchyCarrier
                    ->enable($objectType, $hierarchyCarrier)
                    ->getKey();
            }

            if ($hierarchyEnabled === false) {
                $retiredCarrierId = $objectType->hierarchy_relationship_type_id;
                $attributes['hierarchy_relationship_type_id'] = null;
            }

            $objectType->update($attributes);

            if ($retiredCarrierId !== null) {
                $this->syncHierarchyCarrier->disable($retiredCarrierId);
            }

            return $objectType;
        });
    }

    /**
     * @throws ValidationException
     */
    private function guardBusinessKeyChange(ObjectType $objectType, ?string $prefix, ?string $format): void
    {
        $messages = [];

        if ($this->textOrNull($objectType->business_key_prefix) !== $prefix) {
            $messages['business_key_prefix'] = __('i18n.backend.actions.engine.update_object_type_action.an_object_type_s_code_cannot_be_changed_once');
        }

        if ($this->effectiveRecordNumberFormat($objectType->record_number_format) !== $this->effectiveRecordNumberFormat($format)) {
            $messages['record_number_format'] = __('i18n.backend.actions.engine.update_object_type_action.an_object_type_s_numbering_format_cannot_be_changed');
        }

        if ($messages === [] || !$objectType->hasRecords()) {
            return;
        }

        throw ValidationException::withMessages($messages);
    }

    /**
     * @throws ValidationException
     */
    private function guardStorageStrategyChange(ObjectType $objectType, StorageStrategy $storageStrategy): void
    {
        if ($objectType->storage_strategy === $storageStrategy || !$objectType->hasRecords()) {
            return;
        }

        throw ValidationException::withMessages([
            'storage_strategy' => __('i18n.backend.actions.engine.update_object_type_action.an_object_type_s_storage_strategy_cannot_be_changed'),
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function guardDedupKeys(mixed $dedupKeys): void
    {
        if ($dedupKeys === null || (is_array($dedupKeys) && $this->isDistinctList($dedupKeys, $this->isDedupKeyEntry(...)))) {
            return;
        }

        throw ValidationException::withMessages([
            'dedup_keys' => __('i18n.backend.actions.engine.update_object_type_action.duplicate_keys_must_be_a_list_of_field_keys'),
        ]);
    }

    private function isDedupKeyEntry(mixed $entry): bool
    {
        if (is_array($entry)) {
            return $entry !== [] && $this->isDistinctList($entry, $this->isFieldKey(...));
        }

        return $this->isFieldKey($entry);
    }

    private function isFieldKey(mixed $entry): bool
    {
        return is_string($entry) && $entry !== '';
    }

    /**
     * @param  array<mixed>  $entries
     * @param  callable(mixed): bool  $isValidEntry
     */
    private function isDistinctList(array $entries, callable $isValidEntry): bool
    {
        if (!array_is_list($entries)) {
            return false;
        }

        foreach ($entries as $entry) {
            if (!$isValidEntry($entry)) {
                return false;
            }
        }

        $fingerprints = array_map(static fn (mixed $entry): string => (string) json_encode($entry), $entries);

        return count(array_unique($fingerprints)) === count($entries);
    }

    private function effectiveRecordNumberFormat(?string $format): string
    {
        return $this->textOrNull($format) ?? RecordNumberFormatter::$defaultFormat;
    }

    private function textOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
