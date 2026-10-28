<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Contracts\Modules\RecordCreationExtensionInterface;
use App\Contracts\Modules\RecordMutationExtensionInterface;
use App\Contracts\Modules\RecordPresentationExtensionInterface;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\User;

class RecordExtensions
{
    /** @param iterable<RecordCreationExtensionInterface> $creation
     * @param  iterable<RecordPresentationExtensionInterface>  $presentation
     * @param  iterable<RecordMutationExtensionInterface>  $mutations
     */
    public function __construct(private readonly iterable $creation = [], private readonly iterable $presentation = [], private readonly iterable $mutations = []) {}

    public function saved(CustomRecord $record): void
    {
        foreach ($this->mutations as $extension) {
            $extension->saved($record);
        }
    }

    public function deleting(CustomRecord $record): void
    {
        foreach ($this->mutations as $extension) {
            $extension->deleting($record);
        }
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function attributes(ObjectType $objectType, array $input): array
    {
        $attributes = [];
        foreach ($this->creation as $extension) {
            $attributes = array_merge($attributes, $extension->attributes($objectType, $input));
        }

        return $attributes;
    }

    /** @return array<string, mixed> */
    public function auditAttributes(CustomRecord $record): array
    {
        $attributes = [];
        foreach ($this->creation as $extension) {
            $attributes = array_merge($attributes, $extension->auditAttributes($record));
        }

        return $attributes;
    }

    /** @return array<string, mixed> */
    public function duplicateAttributes(CustomRecord $record): array
    {
        $attributes = [];
        foreach ($this->creation as $extension) {
            $attributes = array_merge($attributes, $extension->duplicateAttributes($record));
        }

        return $attributes;
    }

    /** @return array<string, mixed> */
    public function form(ObjectType $objectType, User $user, ?CustomRecord $record = null): array
    {
        $payload = [];
        foreach ($this->presentation as $extension) {
            $payload = array_merge($payload, $extension->form($objectType, $user, $record));
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function index(ObjectType $objectType, User $user): array
    {
        $payload = [];
        foreach ($this->presentation as $extension) {
            $payload = array_merge($payload, $extension->index($objectType, $user));
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function resource(CustomRecord $record): array
    {
        $payload = [];
        foreach ($this->presentation as $extension) {
            $payload = array_merge($payload, $extension->resource($record));
        }

        return $payload;
    }
}
