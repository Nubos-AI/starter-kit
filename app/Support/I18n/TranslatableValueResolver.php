<?php

declare(strict_types=1);

namespace App\Support\I18n;

class TranslatableValueResolver
{
    public function resolve(mixed $value, ?string $locale = null): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($value === []) {
            return null;
        }

        $locale ??= app()->getLocale();

        if (array_key_exists($locale, $value)) {
            return $value[$locale];
        }

        $fallback = config('app.fallback_locale');

        if (is_string($fallback) && array_key_exists($fallback, $value)) {
            return $value[$fallback];
        }

        return array_values($value)[0];
    }

    /**
     * @param  array<string, mixed>  $map
     * @return array<string, mixed>
     */
    public function put(array $map, string $locale, mixed $value): array
    {
        $map[$locale] = $value;

        return $map;
    }

    /**
     * @param  array<string, mixed>  $map
     * @return array<string, mixed>|null
     */
    public function putText(array $map, mixed $text, ?string $locale = null): ?array
    {
        $locale ??= app()->getLocale();
        $text = is_string($text) ? trim($text) : '';

        if ($text === '') {
            unset($map[$locale]);
        } else {
            $map = $this->put($map, $locale, $text);
        }

        return $map === [] ? null : $map;
    }
}
