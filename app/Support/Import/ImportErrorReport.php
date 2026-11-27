<?php

declare(strict_types=1);

namespace App\Support\Import;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ImportErrorReport
{
    private static string $prefix = 'import:errors:';

    private static int $ttlSeconds = 86400;

    private static int $lockSeconds = 10;

    private static int $lockWaitSeconds = 5;

    public static function add(string $importJobId, int $row, string $reason, ?string $identity = null): void
    {
        Cache::lock(self::$prefix.$importJobId.':lock', self::$lockSeconds)
            ->block(self::$lockWaitSeconds, static function () use ($importJobId, $row, $reason, $identity): void {
                $errors = self::errors($importJobId);
                $errors[] = ['row' => $row, 'reason' => $reason, 'identity' => $identity ?? ''];

                Cache::put(self::$prefix.$importJobId, $errors, self::$ttlSeconds);
            });
    }

    /**
     * @return list<array{row: int, reason: string, identity: string}>
     */
    public static function errors(string $importJobId): array
    {
        $stored = Cache::get(self::$prefix.$importJobId, []);

        return is_array($stored) ? array_values($stored) : [];
    }

    public static function persist(string $importJobId, string $tenantId, string $disk): ?string
    {
        $errors = self::errors($importJobId);

        if ($errors === []) {
            return null;
        }

        usort($errors, static fn (array $a, array $b): int => $a['row'] <=> $b['row']);

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException(__('i18n.backend.support.import.import_error_report.the_error_report_could_not_be_created'));
        }

        fputcsv($handle, [__('i18n.backend.support.import.import_error_report.row'), __('i18n.backend.support.import.import_error_report.record'), __('i18n.backend.support.import.import_error_report.reason')]);

        foreach ($errors as $error) {
            fputcsv($handle, [
                (string) $error['row'],
                self::sanitize($error['identity']),
                self::sanitize($error['reason']),
            ]);
        }

        rewind($handle);

        $path = "imports/{$tenantId}/errors/{$importJobId}.csv";
        Storage::disk($disk)->writeStream($path, $handle);

        fclose($handle);

        Cache::forget(self::$prefix.$importJobId);

        return $path;
    }

    private static function sanitize(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'".$value;
        }

        return $value;
    }
}
