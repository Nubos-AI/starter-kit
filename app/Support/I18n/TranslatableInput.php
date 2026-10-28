<?php

declare(strict_types=1);

namespace App\Support\I18n;

use Illuminate\Http\Request;

class TranslatableInput
{
    /** @var array<string, string> */
    private array $columns = [
        'label' => 'i18n_labels',
        'description' => 'i18n_descriptions',
    ];

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $attributes
     * @return array<string, mixed>
     */
    public function forCreate(Request $request, array $input, array $attributes): array
    {
        foreach ($this->columnsFor($attributes) as $attribute => $column) {
            $value = $this->read($request, $attribute);

            if ($value !== null) {
                $input[$column] = $this->fallbackMap($value);
            }
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $attributes
     * @return array<string, mixed>
     */
    public function forUpdate(Request $request, array $input, array $attributes): array
    {
        foreach ($this->columnsFor($attributes) as $attribute => $column) {
            if (!$request->has($attribute)) {
                continue;
            }

            $value = $this->read($request, $attribute);

            $input[$column] = $value === null ? null : $this->fallbackMap($value);
        }

        return $input;
    }

    /**
     * @param  list<string>  $attributes
     * @return array<string, string>
     */
    private function columnsFor(array $attributes): array
    {
        return array_intersect_key($this->columns, array_flip($attributes));
    }

    private function read(Request $request, string $attribute): ?string
    {
        $value = trim((string) $request->input($attribute, ''));

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, string>
     */
    private function fallbackMap(string $value): array
    {
        $fallbackLocale = config('app.fallback_locale');

        return [is_string($fallbackLocale) ? $fallbackLocale : 'en' => $value];
    }
}
