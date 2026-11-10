<?php

declare(strict_types=1);

namespace App\Traits\Engine;

use App\Actions\Engine\DeleteRecordAction;
use App\Actions\Engine\UpdateRecordAction;
use App\Exceptions\Engine\ReadOnlyRecordFieldException;
use App\Models\FieldDefinition;
use App\Models\User;
use App\Support\CustomFields\FieldTypeRegistry;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Engine\RecordTitleResolver;
use Illuminate\Support\Facades\Auth;
use Throwable;

trait HasDynamicFields
{
    /**
     * @param  string  $key
     */
    public function getAttribute($key): mixed
    {
        if ($this->resolvesAsDynamicField($key)) {
            $field = $this->fieldDefinition($key);

            if ($field instanceof FieldDefinition) {
                return app(FieldTypeRegistry::class)->read($this->rawField($key), $field);
            }
        }

        return parent::getAttribute($key);
    }

    /**
     * @param  string  $key
     */
    public function setAttribute($key, mixed $value): mixed
    {
        if ($this->resolvesAsDynamicField($key)
            && !in_array($key, $this->getFillable(), true)
            && $this->fieldDefinition($key) instanceof FieldDefinition) {
            throw new ReadOnlyRecordFieldException($key);
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * @param  array<string, mixed>  $values
     *
     * @throws Throwable
     */
    public function updateFields(array $values, ?int $expectedVersion = null): static
    {
        $updated = app(UpdateRecordAction::class)->execute($this, [
            'version' => $expectedVersion ?? $this->version,
            'data' => $values,
        ]);

        /** @var static $updated */
        return $updated;
    }

    /**
     * @throws Throwable
     */
    public function deleteRecord(?string $reason = null): void
    {
        app(DeleteRecordAction::class)->execute(
            $this,
            $reason === null ? [] : ['deletion_reason' => $reason],
        );
    }

    public function title(?User $viewer = null): string
    {
        $resolver = RecordTitleResolver::forRequest();
        $viewer ??= Auth::user();

        if ($viewer instanceof User) {
            return $resolver->titleFor($viewer, $this);
        }

        $raw = $resolver->rawTitle($this, $resolver->rawTitleKey($this->object_type_id));

        return is_string($raw) && $raw !== '' ? $raw : (string) $this->record_number;
    }

    public function field(string $key): mixed
    {
        return $this->getAttribute($key);
    }

    public function fieldDefinition(string $key): ?FieldDefinition
    {
        $objectTypeId = $this->relationObjectTypeId();

        return $objectTypeId === null
            ? null
            : app(ObjectTypeRegistry::class)->field($objectTypeId, $key);
    }

    private function resolvesAsDynamicField(mixed $key): bool
    {
        return is_string($key)
            && $key !== ''
            && !$this->hasAttribute($key)
            && !$this->relationLoaded($key)
            && !$this->isRelation($key);
    }

    private function rawField(string $key): mixed
    {
        $data = parent::getAttribute('data');

        return is_array($data) ? ($data[$key] ?? null) : null;
    }
}
