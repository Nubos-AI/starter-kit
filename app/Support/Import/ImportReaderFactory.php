<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportReaderFactory
{
    public function __construct(private readonly FileFormatDetector $fileFormatDetector) {}

    public function uploadDisk(): string
    {
        return (string) config('filesystems.default', 'local');
    }

    public function uploadDirectory(User $user): string
    {
        return "imports/{$user->tenant_id}/{$user->getKey()}";
    }

    public function make(string $disk, string $path, string $format, ?string $sheet, User $user): CsvReader|XlsxReader
    {
        $storage = Storage::disk($disk);

        if (!$this->isOwnUpload($disk, $path, $user) || !$storage->exists($path)) {
            throw ValidationException::withMessages([
                'file' => __('i18n.backend.support.import.import_reader_factory.the_uploaded_file_was_not_found'),
            ]);
        }

        $setting = TenantSetting::forTenant((string) $user->tenant_id);
        $absolutePath = $storage->path($path);

        $this->assertWithinByteLimit($storage->size($path), $setting->import_max_file_bytes);

        return match (mb_strtolower($format)) {
            'xlsx', 'xls' => $this->makeXlsxReader($absolutePath, $sheet, $setting->import_max_rows),
            default => $this->makeCsvReader($absolutePath, $setting->import_max_rows),
        };
    }

    private function isOwnUpload(string $disk, string $path, User $user): bool
    {
        $directory = $this->uploadDirectory($user).'/';

        return $disk === $this->uploadDisk()
            && Str::startsWith($path, $directory)
            && !Str::contains(Str::after($path, $directory), ['/', '\\', '..']);
    }

    private function makeCsvReader(string $absolutePath, int $maxRows): CsvReader
    {
        $this->assertCsvRowsWithinLimit($absolutePath, $maxRows);

        $suggestion = $this->fileFormatDetector->detect($absolutePath);
        $reader = new CsvReader($absolutePath, $suggestion->delimiter, $suggestion->encoding);

        $this->assertUniqueHeader($reader->header());

        return $reader;
    }

    private function makeXlsxReader(string $absolutePath, ?string $sheet, int $maxRows): XlsxReader
    {
        $this->assertXlsxRowsWithinLimit($absolutePath, $sheet, $maxRows);

        $reader = new XlsxReader($absolutePath, $sheet);

        $this->assertUniqueHeader($reader->header());

        return $reader;
    }

    private function assertXlsxRowsWithinLimit(string $absolutePath, ?string $sheet, int $maxRows): void
    {
        $reader = IOFactory::createReaderForFile($absolutePath);
        $reader->setReadDataOnly(true);

        $sheetNames = array_values($reader->listWorksheetNames($absolutePath));
        $targetSheet = $sheet ?? ($sheetNames[0] ?? null);

        $totalRows = 0;

        foreach ($reader->listWorksheetInfo($absolutePath) as $entry) {
            if ($targetSheet === null || $entry['worksheetName'] === $targetSheet) {
                $totalRows = $entry['totalRows'];

                break;
            }
        }

        if ($totalRows > $maxRows + 1) {
            throw ValidationException::withMessages([
                'file' => __('i18n.backend.support.import.import_reader_factory.the_file_exceeds_the_limit_of_data_rows', ['value1' => $maxRows]),
            ]);
        }
    }

    private function assertWithinByteLimit(int $bytes, int $maxBytes): void
    {
        if ($bytes > $maxBytes) {
            throw ValidationException::withMessages([
                'file' => __('i18n.backend.support.import.import_reader_factory.the_file_exceeds_the_size_limit_of_bytes', ['value1' => $maxBytes]),
            ]);
        }
    }

    private function assertCsvRowsWithinLimit(string $absolutePath, int $maxRows): void
    {
        $handle = @fopen($absolutePath, 'rb');

        if ($handle === false) {
            return;
        }

        $limit = $maxRows + 1;
        $lines = 0;

        while (!feof($handle)) {
            $line = fgets($handle);

            if ($line === false) {
                break;
            }

            if (trim($line) === '') {
                continue;
            }

            $lines++;

            if ($lines > $limit) {
                fclose($handle);

                throw ValidationException::withMessages([
                    'file' => __('i18n.backend.support.import.import_reader_factory.the_file_exceeds_the_limit_of_data_rows', ['value1' => $maxRows]),
                ]);
            }
        }

        fclose($handle);
    }

    /**
     * @param  list<string>  $header
     */
    private function assertUniqueHeader(array $header): void
    {
        $normalized = array_map(static fn (string $value): string => trim($value), $header);
        $nonEmpty = array_filter($normalized, static fn (string $value): bool => $value !== '');

        if (count($nonEmpty) !== count($normalized) || $normalized !== array_values(array_unique($normalized))) {
            throw ValidationException::withMessages([
                'file' => __('i18n.backend.support.import.import_reader_factory.the_header_contains_duplicate_or_ambiguous_column_names'),
            ]);
        }
    }
}
