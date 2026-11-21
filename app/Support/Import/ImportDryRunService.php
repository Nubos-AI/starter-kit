<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Enums\Import\ImportDuplicateMode;
use App\Enums\Import\ImportMissingOptionMode;
use App\Models\ObjectType;
use App\Models\User;

class ImportDryRunService
{
    private int $sampleErrorLimit = 50;

    private int $headerRowNumber = 1;

    public function __construct(
        private readonly ImportRowClassifier $classifier,
        private readonly ImportRecordLocator $locator,
    ) {}

    /**
     * @param  array<string, mixed>  $mapping
     * @return array{new: int, updates: int, errors: int, duplicateDetection: bool, sampleErrors: list<array{row: int, message: string}>}
     */
    public function run(
        ObjectType $objectType,
        User $user,
        CsvReader|XlsxReader $reader,
        array $mapping,
        ImportDuplicateMode $mode,
        ImportMissingOptionMode $missingOptionMode = ImportMissingOptionMode::Error,
    ): array {
        $tenantId = (string) $user->tenant_id;

        $new = 0;
        $updates = 0;
        $errors = 0;

        /** @var list<array{row: int, message: string}> $sampleErrors */
        $sampleErrors = [];

        $rowNumber = $this->headerRowNumber;

        foreach ($reader->rows() as $row) {
            $rowNumber++;

            $classification = $this->classifier->classify($row, $objectType, $mapping, $mode, $tenantId, $rowNumber, $missingOptionMode);

            switch ($classification['status']) {
                case 'insert':
                    $new++;

                    break;
                case 'update':
                    $updates++;

                    break;
                case 'error':
                    $errors++;

                    if (count($sampleErrors) < $this->sampleErrorLimit) {
                        $sampleErrors[] = [
                            'row' => $classification['row'],
                            'message' => $classification['message'] ?? __('i18n.backend.support.import.import_dry_run_service.the_row_could_not_be_processed'),
                        ];
                    }

                    break;
            }
        }

        return [
            'new' => $new,
            'updates' => $updates,
            'errors' => $errors,
            'duplicateDetection' => $this->locator->detectsDuplicates($objectType),
            'sampleErrors' => $sampleErrors,
        ];
    }
}
