<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Support\Storage\AttachmentStorage;
use Illuminate\Support\Facades\Log;

class DeleteObjectTypeFilesAction
{
    public function __construct(private readonly AttachmentStorage $attachmentStorage) {}

    /**
     * @param  list<array{disk: string, path: string}>  $files
     */
    public function execute(string $objectTypeId, array $files): int
    {
        $failed = 0;

        foreach ($files as $file) {
            if ($this->attachmentStorage->delete($file['disk'], $file['path'])) {
                continue;
            }

            $failed++;

            Log::warning('An attachment file of a purged object type could not be deleted.', [
                'object_type_id' => $objectTypeId,
                'disk' => $file['disk'],
                'path' => $file['path'],
            ]);
        }

        return count($files) - $failed;
    }
}
