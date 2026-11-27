<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Enums\CustomFields\FieldType;
use App\Enums\Watchers\WatcherSource;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\RecordWatcher;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\ObjectTypeFieldLookup;
use App\Support\Modules\RecordExtensions;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Throwable;

class DuplicateRecordAction
{
    public function __construct(
        private readonly RecordExtensions $extensions,
        private readonly CreateRecordWithCollaboratorsAction $createRecordAction,
        private readonly ObjectTypeFieldLookup $fieldLookup,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(CustomRecord $record): CustomRecord
    {
        $collaboratorIds = $this->collaboratorIds($record);

        return $this->createRecordAction->execute(
            [
                'object_type_id' => $record->object_type_id,
                'tenant_id' => $record->tenant_id,
                'team_id' => $record->team_id,
                'owner_id' => $record->owner_id,
                ...$this->extensions->duplicateAttributes($record),
                'data' => $this->writableData($record),
            ],
            $collaboratorIds === [] ? null : ['collaborator_ids' => $collaboratorIds],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function writableData(CustomRecord $record): ?array
    {
        if ($record->data === null) {
            return null;
        }

        $data = Arr::except($record->data, $this->fileFieldKeys($record->object_type_id));
        $actor = Auth::user();

        if (!$actor instanceof User) {
            return $data;
        }

        return Arr::except(
            $data,
            FieldVisibilityResolver::forRequest()->forbiddenWriteFieldKeys($actor, $record->object_type_id),
        );
    }

    /**
     * @return list<string>
     */
    private function fileFieldKeys(string $objectTypeId): array
    {
        return array_values($this->fieldLookup->fields($objectTypeId)
            ->filter(static fn (FieldDefinition $field): bool => $field->field_type === FieldType::File)
            ->map(static fn (FieldDefinition $field): string => $field->key)
            ->all());
    }

    /**
     * @return list<string>
     */
    private function collaboratorIds(CustomRecord $record): array
    {
        return array_values(
            RecordWatcher::query()
                ->where('record_id', $record->getKey())
                ->where('source', WatcherSource::Collaborator)
                ->pluck('user_id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all()
        );
    }
}
