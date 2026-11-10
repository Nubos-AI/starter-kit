<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Exceptions\Engine\ReservedSlugException;
use App\Models\ObjectType;
use Illuminate\Support\Str;

class SlugGenerator
{
    public function generate(string $source): string
    {
        $base = Str::slug($source);

        if ($base === '') {
            throw ReservedSlugException::forUnusableName($source);
        }

        /** @var list<string> $reserved */
        $reserved = config('engine.reserved_slugs', []);

        if (in_array($base, $reserved, true)) {
            throw ReservedSlugException::forSlug($base);
        }

        $slug = $base;
        $suffix = 2;

        while ($this->exists($slug)) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private function exists(string $slug): bool
    {
        return ObjectType::withTrashed()->where('slug', $slug)->exists();
    }
}
