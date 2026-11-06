<?php

declare(strict_types=1);

namespace App\Support\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class AttachmentStorage
{
    /**
     * @return array{disk: string, path: string}
     */
    public function store(UploadedFile $file, string $directory, ?string $disk = null): array
    {
        $disk = $disk ?? $this->defaultDisk();

        $path = Storage::disk($disk)->putFileAs($directory, $file, $this->safeFilename($file), ['visibility' => 'private']);

        if (!is_string($path)) {
            throw new RuntimeException(__('i18n.backend.support.storage.attachment_storage.the_file_could_not_be_saved'));
        }

        return ['disk' => $disk, 'path' => $path];
    }

    public function get(string $disk, string $path): ?string
    {
        return Storage::disk($disk)->get($path);
    }

    /**
     * @return resource|null
     */
    public function readStream(string $disk, string $path)
    {
        return Storage::disk($disk)->readStream($path);
    }

    public function delete(string $disk, string $path): bool
    {
        return Storage::disk($disk)->delete($path);
    }

    private function safeFilename(UploadedFile $file): string
    {
        $name = (string) Str::ulid();
        $extension = $file->extension();

        if (is_string($extension) && preg_match('/^[a-z0-9]{1,16}$/i', $extension) === 1) {
            return $name.'.'.$extension;
        }

        return $name;
    }

    private function defaultDisk(): string
    {
        return (string) config('filesystems.default');
    }
}
