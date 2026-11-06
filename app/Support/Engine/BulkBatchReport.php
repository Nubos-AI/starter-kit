<?php

declare(strict_types=1);

namespace App\Support\Engine;

use Illuminate\Support\Facades\Cache;

class BulkBatchReport
{
    private static string $errorsPrefix = 'bulk:errors:';

    private static string $metaPrefix = 'bulk:meta:';

    private static string $progressPrefix = 'bulk:progress:';

    private static int $ttlSeconds = 86400;

    private static int $lockSeconds = 10;

    private static int $lockWaitSeconds = 5;

    public static function initProgress(string $batchId, string $tenantId, string $userId, int $total): void
    {
        Cache::put(
            self::$progressPrefix.$batchId,
            ['tenant_id' => $tenantId, 'user_id' => $userId, 'total' => $total, 'processed' => 0, 'finished' => false],
            self::$ttlSeconds,
        );
    }

    public static function markChunkProcessed(string $batchId): void
    {
        self::mutateProgress($batchId, static function (array $progress): array {
            $progress['processed'] = (int) $progress['processed'] + 1;

            return $progress;
        });
    }

    public static function markFinished(string $batchId): void
    {
        self::mutateProgress($batchId, static function (array $progress): array {
            $progress['finished'] = true;

            return $progress;
        });
    }

    /**
     * @return array{tenant_id: string, user_id: string, total: int, processed: int, finished: bool}|null
     */
    public static function progress(string $batchId): ?array
    {
        $stored = Cache::get(self::$progressPrefix.$batchId);

        if (!is_array($stored) || !isset($stored['tenant_id'])) {
            return null;
        }

        return [
            'tenant_id' => (string) $stored['tenant_id'],
            'user_id' => (string) ($stored['user_id'] ?? ''),
            'total' => (int) ($stored['total'] ?? 0),
            'processed' => (int) ($stored['processed'] ?? 0),
            'finished' => (bool) ($stored['finished'] ?? false),
        ];
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutator
     */
    private static function mutateProgress(string $batchId, callable $mutator): void
    {
        Cache::lock(self::$progressPrefix.$batchId.':lock', self::$lockSeconds)
            ->block(self::$lockWaitSeconds, static function () use ($batchId, $mutator): void {
                $current = Cache::get(self::$progressPrefix.$batchId);

                if (!is_array($current)) {
                    return;
                }

                Cache::put(self::$progressPrefix.$batchId, $mutator($current), self::$ttlSeconds);
            });
    }

    /**
     * @param  list<array{recordId: string, message: string}>  $errors
     */
    public static function addErrors(string $batchId, array $errors): void
    {
        if ($errors === []) {
            return;
        }

        Cache::lock(self::$errorsPrefix.$batchId.':lock', self::$lockSeconds)
            ->block(self::$lockWaitSeconds, static function () use ($batchId, $errors): void {
                Cache::put(
                    self::$errorsPrefix.$batchId,
                    array_merge(self::errors($batchId), $errors),
                    self::$ttlSeconds,
                );
            });
    }

    /**
     * @return list<array{recordId: string, message: string}>
     */
    public static function errors(string $batchId): array
    {
        $stored = Cache::get(self::$errorsPrefix.$batchId, []);

        return is_array($stored) ? array_values($stored) : [];
    }

    public static function setDownloadPath(string $batchId, string $path): void
    {
        Cache::put(self::$metaPrefix.$batchId.':download_path', $path, self::$ttlSeconds);
    }

    public static function downloadPath(string $batchId): ?string
    {
        $path = Cache::get(self::$metaPrefix.$batchId.':download_path');

        return is_string($path) ? $path : null;
    }
}
