<?php

declare(strict_types=1);

namespace App\Http\Controllers\Import;

use App\Enums\Import\ImportDuplicateMode;
use App\Enums\Import\ImportMissingOptionMode;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\ObjectType;
use App\Support\Import\ImportDryRunService;
use App\Support\Import\ImportMappingValidator;
use App\Support\Import\ImportReaderFactory;
use App\Support\Import\MissingOptionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ImportPreviewsController extends Controller
{
    public function __construct(
        private readonly ImportReaderFactory $readerFactory,
        private readonly ImportMappingValidator $mappingValidator,
        private readonly ImportDryRunService $dryRunService,
        private readonly MissingOptionResolver $missingOptionResolver,
    ) {}

    public function preview(Request $request, ObjectType $objectType): JsonResponse
    {
        $user = $this->actingUser($request);

        $validated = $request->validate([
            'path' => ['required', 'string'],
            'format' => ['required', 'string', 'in:csv,xlsx,xls'],
            'sheet' => ['nullable', 'string'],
            'mapping' => ['required', 'array'],
            'duplicate_mode' => ['required', 'string', 'in:skip,upsert,insert'],
            'missing_option_mode' => ['nullable', 'string', 'in:create,error'],
        ]);

        /** @var array<string, mixed> $mapping */
        $mapping = $validated['mapping'];

        $missingOptionMode = $this->missingOptionResolver->effectiveMode(
            ImportMissingOptionMode::from((string) ($validated['missing_option_mode'] ?? 'error')),
            $user,
            $objectType,
        );

        try {
            $this->mappingValidator->validate($objectType, $user, $mapping);

            $reader = $this->readerFactory->make(
                $this->readerFactory->uploadDisk(),
                (string) $validated['path'],
                (string) $validated['format'],
                isset($validated['sheet']) ? (string) $validated['sheet'] : null,
                $user,
            );

            $report = $this->dryRunService->run(
                $objectType,
                $user,
                $reader,
                $mapping,
                ImportDuplicateMode::from((string) $validated['duplicate_mode']),
                $missingOptionMode,
            );
        } catch (ValidationException $exception) {
            return new JsonResponse([
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], 422);
        }

        return new JsonResponse($report);
    }
}
