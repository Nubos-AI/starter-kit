<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Models\RelationshipType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class RelationshipKeyGenerator
{
    private string $fallback = 'relationship';

    /**
     * @param  list<string>  $reserved
     */
    public function generate(string $name, array $reserved = []): string
    {
        $base = Str::slug($name) !== '' ? Str::slug($name) : $this->fallback;
        $key = $base;
        $suffix = 1;

        while (in_array($key, $reserved, true) || $this->taken($key)) {
            $suffix++;
            $key = $base.'-'.$suffix;
        }

        return $key;
    }

    private function taken(string $key): bool
    {
        return RelationshipType::query()
            ->withTrashed()
            ->where(fn (Builder $query): Builder => $query
                ->where('key', $key)
                ->orWhere('inverse_key', $key))
            ->exists();
    }
}
