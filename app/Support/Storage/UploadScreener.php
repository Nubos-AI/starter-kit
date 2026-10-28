<?php

declare(strict_types=1);

namespace App\Support\Storage;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ZipArchive;

class UploadScreener
{
    private string $oleSignature = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    private string $oleMacroStream = "_\0V\0B\0A\0_\0P\0R\0O\0J\0E\0C\0T\0";

    private string $macroEntryPattern = '#(^|/)vbaProject\.bin$|^(basic|scripts)/#i';

    /**
     * @return Closure(string, mixed, Closure): void
     */
    public function rule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (!$value instanceof UploadedFile) {
                return;
            }

            $refusal = $this->refusalOf($value);

            if ($refusal !== null) {
                $fail(__($refusal));
            }
        };
    }

    public function refusalOf(UploadedFile $file): ?string
    {
        $refusal = $this->typeRefusal($file) ?? $this->contentRefusal($file);

        if ($refusal !== null) {
            Log::warning('Upload refused by the safety screening.', [
                'reason' => Str::afterLast($refusal, '.'),
                'extension' => Str::lower($file->getClientOriginalExtension()),
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
            ]);
        }

        return $refusal;
    }

    public function isBlockedMime(string $mime): bool
    {
        return in_array(Str::lower($mime), $this->blockedMimes(), true);
    }

    private function typeRefusal(UploadedFile $file): ?string
    {
        $extension = Str::lower($file->getClientOriginalExtension());
        $sniffed = (string) $file->getMimeType();

        if (in_array($extension, $this->blockedExtensions(), true) || $this->isBlockedMime($sniffed)) {
            return 'i18n.backend.support.storage.upload_screener.blocked_type';
        }

        return null;
    }

    private function contentRefusal(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();

        if ($path === false) {
            return null;
        }

        $head = (string) file_get_contents($path, false, null, 0, 8);

        if (str_starts_with($head, 'PK')) {
            return $this->archiveRefusal($path);
        }

        if ($head === $this->oleSignature && str_contains((string) file_get_contents($path), $this->oleMacroStream)) {
            return 'i18n.backend.support.storage.upload_screener.macros';
        }

        return null;
    }

    private function archiveRefusal(string $path): ?string
    {
        $archive = new ZipArchive;

        if ($archive->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $refusal = $this->entryRefusal($archive->statIndex($index));

                if ($refusal !== null) {
                    return $refusal;
                }
            }
        } finally {
            $archive->close();
        }

        return null;
    }

    /**
     * @param  array{name: string, encryption_method: int}|false  $entry
     */
    private function entryRefusal(array|false $entry): ?string
    {
        if ($entry === false) {
            return null;
        }

        if ($entry['encryption_method'] !== ZipArchive::EM_NONE) {
            return 'i18n.backend.support.storage.upload_screener.encrypted';
        }

        if (preg_match($this->macroEntryPattern, $entry['name']) === 1) {
            return 'i18n.backend.support.storage.upload_screener.macros';
        }

        if (in_array(Str::lower(pathinfo($entry['name'], PATHINFO_EXTENSION)), $this->blockedExtensions(), true)) {
            return 'i18n.backend.support.storage.upload_screener.blocked_content';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function blockedExtensions(): array
    {
        /** @var list<string> $extensions */
        $extensions = config('engine.attachments.blocked_extensions', []);

        return $extensions;
    }

    /**
     * @return list<string>
     */
    private function blockedMimes(): array
    {
        /** @var list<string> $mimes */
        $mimes = config('engine.attachments.blocked_mimes', []);

        return $mimes;
    }
}
