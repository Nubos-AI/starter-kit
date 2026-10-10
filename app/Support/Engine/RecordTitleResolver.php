<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Enums\CustomFields\ReservedFieldKey;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;

class RecordTitleResolver
{
    /**
     * @var array<string, string|null>
     */
    private array $titleKeys = [];

    public static function forRequest(): self
    {
        if (!app()->bound(self::class)) {
            app()->instance(self::class, new self);
        }

        /** @var self $resolver */
        $resolver = app()->make(self::class);

        return $resolver;
    }

    public function titleKey(User $user, string $objectTypeId): ?string
    {
        $cacheKey = $user->getKey().'|'.$objectTypeId;

        if (array_key_exists($cacheKey, $this->titleKeys)) {
            return $this->titleKeys[$cacheKey];
        }

        $readable = FieldVisibilityResolver::forRequest()->readableFieldKeys($user, $objectTypeId);

        $candidates = app(ObjectTypeRegistry::class)
            ->fields($objectTypeId)
            ->filter(static fn (FieldDefinition $field): bool => $field->is_default_column
                && !$field->is_encrypted
                && in_array($field->key, $readable, true))
            ->values();

        $field = $candidates->first(
            static fn (FieldDefinition $field): bool => $field->key === ReservedFieldKey::Name->value,
        ) ?? $candidates->first();

        return $this->titleKeys[$cacheKey] = $field?->key;
    }

    public function rawTitleKey(string $objectTypeId): ?string
    {
        $candidates = app(ObjectTypeRegistry::class)
            ->fields($objectTypeId)
            ->filter(static fn (FieldDefinition $field): bool => $field->is_default_column && !$field->is_encrypted)
            ->values();

        $field = $candidates->first(
            static fn (FieldDefinition $field): bool => $field->key === ReservedFieldKey::Name->value,
        ) ?? $candidates->first();

        return $field?->key;
    }

    public function rawTitle(CustomRecord $record, ?string $titleKey): mixed
    {
        $data = is_array($record->data) ? $record->data : [];

        if ($titleKey !== null && ($data[$titleKey] ?? null) !== null && $data[$titleKey] !== '') {
            return $data[$titleKey];
        }

        return $record->record_number ?? $record->getKey();
    }

    public function titleFor(User $user, CustomRecord $record): string
    {
        return (string) $this->rawTitle($record, $this->titleKey($user, $record->object_type_id));
    }
}
