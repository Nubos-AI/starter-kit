<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Models\ObjectType;
use App\Models\Permission;
use App\Support\Authorization\PermissionCatalog;
use App\Support\Engine\IndexRegistry;
use App\Support\Storage\AttachmentStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class PurgeObjectTypeAction
{
    public function __construct(
        private readonly AttachmentStorage $attachmentStorage,
        private readonly PermissionCatalog $permissionCatalog,
        private readonly IndexRegistry $indexRegistry,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(ObjectType $objectType): void
    {
        if (!$objectType->trashed()) {
            throw new InvalidArgumentException(__('i18n.backend.actions.engine.purge_object_type_action.only_a_deleted_object_type_can_be_purged'));
        }

        $objectTypeId = (string) $objectType->getKey();

        DB::transaction(function () use ($objectType, $objectTypeId): void {
            $files = DB::table('attachments')
                ->whereIn('record_id', DB::table('custom_records')->select('id')->where('object_type_id', $objectTypeId))
                ->get(['disk', 'path']);

            Permission::query()
                ->whereIn('name', $this->permissionCatalog->namesForObjectType($objectType))
                ->where('group', $objectType->slug)
                ->delete();

            $objectType->forceDelete();

            DB::afterCommit(function () use ($files, $objectTypeId): void {
                $this->indexRegistry->dropObjectTypeIndexes($objectTypeId);

                foreach ($files as $file) {
                    if (!$this->attachmentStorage->delete((string) $file->disk, (string) $file->path)) {
                        Log::warning('An attachment file of a purged object type could not be deleted.', [
                            'object_type_id' => $objectTypeId,
                            'disk' => $file->disk,
                            'path' => $file->path,
                        ]);
                    }
                }
            });
        });
    }
}
