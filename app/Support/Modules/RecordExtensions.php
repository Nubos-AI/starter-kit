<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Contracts\Modules\RecordCreationExtensionInterface;
use App\Contracts\Modules\RecordMutationExtensionInterface;
use App\Contracts\Modules\RecordPresentationExtensionInterface;
use App\Contracts\Modules\RecordRelocatedFieldsInterface;
use App\Contracts\Modules\RecordSuppliedValuesInterface;
use App\Contracts\Modules\RecordUpdateExtensionInterface;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\User;

class RecordExtensions
{
    /** @param iterable<RecordCreationExtensionInterface> $creation
     * @param  iterable<RecordPresentationExtensionInterface>  $presentation
     * @param  iterable<RecordMutationExtensionInterface>  $mutations
     * @param  iterable<RecordUpdateExtensionInterface>  $updates
     * @param  iterable<RecordSuppliedValuesInterface>  $suppliers
     * @param  iterable<RecordRelocatedFieldsInterface>  $relocators
     */
    public function __construct(private readonly RegisteredModuleComponents $components, private readonly iterable $creation = [], private readonly iterable $presentation = [], private readonly iterable $mutations = [], private readonly iterable $updates = [], private readonly iterable $suppliers = [], private readonly iterable $relocators = []) {}

    public function saved(CustomRecord $record): void
    {
        foreach ($this->components->only($this->mutations) as $extension) {
            $extension->saved($record);
        }
    }

    /** @param array<string, mixed> $input */
    public function created(CustomRecord $record, array $input): void
    {
        foreach ($this->components->only($this->creation) as $extension) {
            $extension->created($record, $input);
        }
    }

    /** @param array<string, mixed> $input */
    public function updated(CustomRecord $record, array $input): void
    {
        foreach ($this->components->only($this->updates) as $extension) {
            $extension->updated($record, $input);
        }
    }

    public function deleting(CustomRecord $record): void
    {
        foreach ($this->components->only($this->mutations) as $extension) {
            $extension->deleting($record);
        }
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function attributes(ObjectType $objectType, array $input): array
    {
        $attributes = [];
        foreach ($this->components->only($this->creation) as $extension) {
            $attributes = array_merge($attributes, $extension->attributes($objectType, $input));
        }

        return $attributes;
    }

    /** @return array<string, mixed> */
    public function auditAttributes(CustomRecord $record): array
    {
        $attributes = [];
        foreach ($this->components->only($this->creation) as $extension) {
            $attributes = array_merge($attributes, $extension->auditAttributes($record));
        }

        return $attributes;
    }

    /** @return array<string, mixed> */
    public function duplicateAttributes(CustomRecord $record): array
    {
        $attributes = [];
        foreach ($this->components->only($this->creation) as $extension) {
            $attributes = array_merge($attributes, $extension->duplicateAttributes($record));
        }

        return $attributes;
    }

    /** @return array<string, mixed> */
    public function form(ObjectType $objectType, User $user, ?CustomRecord $record = null): array
    {
        $payload = [];
        foreach ($this->components->only($this->presentation) as $extension) {
            $payload = array_merge($payload, $extension->form($objectType, $user, $record));
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function index(ObjectType $objectType, User $user): array
    {
        $payload = [];
        foreach ($this->components->only($this->presentation) as $extension) {
            $payload = array_merge($payload, $extension->index($objectType, $user));
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function resource(CustomRecord $record): array
    {
        $payload = [];
        foreach ($this->components->only($this->presentation) as $extension) {
            $payload = array_merge($payload, $extension->resource($record));
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function suppliedValues(CustomRecord $record): array
    {
        $values = [];
        foreach ($this->components->only($this->suppliers) as $supplier) {
            $values = array_merge($values, $supplier->suppliedValues($record));
        }

        return $values;
    }

    /** @return list<string> */
    public function relocatedFieldKeys(ObjectType $objectType): array
    {
        $keys = [];
        foreach ($this->components->only($this->relocators) as $relocator) {
            $keys = [...$keys, ...$relocator->relocatedFieldKeys($objectType)];
        }

        return array_values(array_unique($keys));
    }
}
