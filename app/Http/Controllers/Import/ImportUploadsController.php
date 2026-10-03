<?php

declare(strict_types=1);

namespace App\Http\Controllers\Import;

use App\Http\Controllers\Abstracts\Controller;
use App\Models\ObjectType;
use App\Support\Import\CsvReader;
use App\Support\Import\FileFormatDetector;
use App\Support\Import\ImportReaderFactory;
use App\Support\Import\XlsxReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ImportUploadsController extends Controller
{
    /**
     * @var array<string, string>
     */
    private array $extensionFormats = [
        'csv' => 'csv',
        'txt' => 'csv',
        'xlsx' => 'xlsx',
        'xls' => 'xlsx',
    ];

    public function __construct(
        private readonly FileFormatDetector $fileFormatDetector,
        private readonly ImportReaderFactory $readerFactory,
    ) {}

    public function store(Request $request, ObjectType $objectType): JsonResponse
    {
        $user = $this->actingUser($request);

        $request->validate([
            'file' => ['required', 'file'],
        ]);

        $file = $request->file('file');

        if (!$file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => __('i18n.backend.http.controllers.import.import_uploads_controller.no_file_was_uploaded')]);
        }

        $extension = $this->sanitizedExtension($file);
        $format = $this->extensionFormats[$extension];

        $disk = $this->readerFactory->uploadDisk();
        $directory = $this->readerFactory->uploadDirectory($user);
        $filename = (string) Str::ulid().'.'.$extension;

        $path = Storage::disk($disk)->putFileAs($directory, $file, $filename);

        if ($path === false) {
            throw ValidationException::withMessages(['file' => __('i18n.backend.http.controllers.import.import_uploads_controller.the_file_could_not_be_saved')]);
        }

        return new JsonResponse([
            'path' => $path,
            'format' => $format,
            'sheets' => $this->sheets($disk, $path, $format),
            'headerSuggestion' => $this->headerSuggestion($disk, $path, $format),
        ]);
    }

    private function sanitizedExtension(UploadedFile $file): string
    {
        $extension = mb_strtolower($file->getClientOriginalExtension());

        if (!array_key_exists($extension, $this->extensionFormats)) {
            throw ValidationException::withMessages([
                'file' => __('i18n.backend.http.controllers.import.import_uploads_controller.only_csv_and_xlsx_files_are_supported'),
            ]);
        }

        return $extension;
    }

    /**
     * @return list<string>
     */
    private function sheets(string $disk, string $path, string $format): array
    {
        if ($format !== 'xlsx') {
            return [];
        }

        try {
            return (new XlsxReader(Storage::disk($disk)->path($path)))->sheetNames();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    private function headerSuggestion(string $disk, string $path, string $format): array
    {
        $absolutePath = Storage::disk($disk)->path($path);

        try {
            if ($format === 'xlsx') {
                return (new XlsxReader($absolutePath))->header();
            }

            $suggestion = $this->fileFormatDetector->detect($absolutePath);

            return (new CsvReader($absolutePath, $suggestion->delimiter, $suggestion->encoding))->header();
        } catch (Throwable) {
            return [];
        }
    }
}
