<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Models\Attachment;
use App\Models\CustomRecord;
use App\Support\Storage\AttachmentStorage;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class DeleteAttachmentAction
{
    public function __construct(private readonly AttachmentStorage $storage) {}

    /**
     * @throws Throwable
     */
    public function execute(Attachment $attachment): void
    {
        DB::transaction(function () use ($attachment): void {
            $record = CustomRecord::query()->whereKey($attachment->record_id)->lockForUpdate()->firstOrFail();
            $attachment = Attachment::query()->whereKey($attachment->getKey())->lockForUpdate()->firstOrFail();
            $key = $attachment->field_key;
            $data = $record->data ?? [];

            if ($key !== null && array_key_exists($key, $data)) {
                $value = $data[$key];
                $data[$key] = is_array($value)
                    ? array_values(array_filter($value, fn (mixed $id): bool => $id !== $attachment->getKey()))
                    : ($value === $attachment->getKey() ? null : $value);
                $record->data = $data;
                $record->save();
            }

            $attachment->delete();

            if (!$this->storage->delete($attachment->disk, $attachment->path)) {
                throw new RuntimeException(__('i18n.backend.actions.engine.delete_attachment_action.the_file_could_not_be_deleted_from_storage'));
            }
        });
    }
}
