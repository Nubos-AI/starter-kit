<?php

declare(strict_types=1);

namespace App\Support\Promotion;

use App\Models\PromotionBaseline;
use Illuminate\Support\Facades\DB;
use Throwable;

class PromotionBaselineStore
{
    /**
     * @return array<string, string>
     */
    public function hashesFor(string $counterpartKey): array
    {
        return PromotionBaseline::query()
            ->where('counterpart_key', $counterpartKey)
            ->orderBy('id')
            ->pluck('hash', 'artifact_key')
            ->all();
    }

    /**
     * @param  array<string, string>  $hashes
     *
     * @throws Throwable
     */
    public function remember(string $counterpartKey, array $hashes): void
    {
        DB::transaction(function () use ($counterpartKey, $hashes): void {
            PromotionBaseline::query()
                ->where('counterpart_key', $counterpartKey)
                ->whereNotIn('artifact_key', array_keys($hashes))
                ->delete();

            foreach ($hashes as $artifactKey => $hash) {
                $this->rememberOne($counterpartKey, $artifactKey, $hash);
            }
        });
    }

    public function rememberOne(string $counterpartKey, string $artifactKey, string $hash): void
    {
        PromotionBaseline::query()->updateOrCreate(
            [
                'counterpart_key' => $counterpartKey,
                'artifact_key' => $artifactKey,
            ],
            [
                'hash' => $hash,
                'synced_at' => now(),
            ],
        );
    }

    public function forgetOne(string $counterpartKey, string $artifactKey): void
    {
        PromotionBaseline::query()
            ->where('counterpart_key', $counterpartKey)
            ->where('artifact_key', $artifactKey)
            ->delete();
    }

    public function forget(string $counterpartKey): void
    {
        PromotionBaseline::query()
            ->where('counterpart_key', $counterpartKey)
            ->delete();
    }
}
