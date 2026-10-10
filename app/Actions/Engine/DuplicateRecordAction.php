<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Actions\Records\SyncRecordCollaboratorsAction;
use App\Enums\Watchers\WatcherSource;
use App\Models\CustomRecord;
use App\Models\RecordWatcher;
use App\Support\Modules\RecordExtensions;
use Throwable;

class DuplicateRecordAction
{
    public function __construct(
        private readonly RecordExtensions $extensions,
        private readonly CreateRecordAction $createRecordAction,
        private readonly SyncRecordCollaboratorsAction $syncRecordCollaborators,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(CustomRecord $record): CustomRecord
    {
        $copy = $this->createRecordAction->executeWithoutProtocol([
            'object_type_id' => $record->object_type_id,
            'tenant_id' => $record->tenant_id,
            'team_id' => $record->team_id,
            'owner_id' => $record->owner_id,
            ...$this->extensions->duplicateAttributes($record),
            'data' => $record->data,
        ]);

        $collaboratorIds = $this->collaboratorIds($record);

        if ($collaboratorIds !== []) {
            $this->syncRecordCollaborators->execute($copy, ['collaborator_ids' => $collaboratorIds]);
        }

        return $copy;
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
