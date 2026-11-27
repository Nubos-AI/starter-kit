<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Contracts\Outbox\OutboxWriter;
use App\DTOs\Outbox\OutboxSubject;
use App\Enums\Outbox\OutboxTopic;
use App\Models\ObjectType;
use App\Models\Permission;
use App\Support\Authorization\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class PurgeObjectTypeAction
{
    public function __construct(
        private readonly PermissionCatalog $permissionCatalog,
        private readonly OutboxWriter $outbox,
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
                ->get(['disk', 'path'])
                ->map(static fn (object $file): array => ['disk' => (string) $file->disk, 'path' => (string) $file->path])
                ->values()
                ->all();

            Permission::query()
                ->whereIn('name', $this->permissionCatalog->namesForObjectType($objectType))
                ->where('group', $objectType->slug)
                ->delete();

            $objectType->forceDelete();

            $this->outbox->record(
                OutboxTopic::ObjectTypePurged->value,
                $objectType->tenant_id,
                OutboxSubject::of($objectType),
                ['files' => $files],
            );
        });
    }
}
