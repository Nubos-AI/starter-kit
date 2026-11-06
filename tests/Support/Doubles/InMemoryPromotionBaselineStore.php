<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Support\Promotion\PromotionBaselineStore;

class InMemoryPromotionBaselineStore extends PromotionBaselineStore
{
    /**
     * @var array<string, array<string, string>>
     */
    private array $hashes = [];

    /**
     * @return array<string, string>
     */
    public function hashesFor(string $counterpartKey): array
    {
        return $this->hashes[$counterpartKey] ?? [];
    }

    /**
     * @param  array<string, string>  $hashes
     */
    public function remember(string $counterpartKey, array $hashes): void
    {
        $this->hashes[$counterpartKey] = $hashes;
    }

    public function rememberOne(string $counterpartKey, string $artifactKey, string $hash): void
    {
        $this->hashes[$counterpartKey][$artifactKey] = $hash;
    }

    public function forgetOne(string $counterpartKey, string $artifactKey): void
    {
        unset($this->hashes[$counterpartKey][$artifactKey]);
    }

    public function forget(string $counterpartKey): void
    {
        unset($this->hashes[$counterpartKey]);
    }
}
