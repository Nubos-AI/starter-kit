<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Models\FieldDefinition;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Carbon;

class DefaultValueResolver
{
    public function __construct(private readonly AuthFactory $auth) {}

    /**
     * @param  iterable<FieldDefinition>  $fields
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function applyStaticDefaults(iterable $fields, array $data): array
    {
        foreach ($fields as $field) {
            if ($this->hasExplicitValue($field->key, $data)) {
                continue;
            }

            $value = $this->resolvePreValidation($field);

            if ($value !== null) {
                $data[$field->key] = $value;
            }
        }

        return $data;
    }

    /**
     * @param  iterable<FieldDefinition>  $fields
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function applySequenceDefaults(iterable $fields, array $data, ?string $recordNumber): array
    {
        if ($recordNumber === null) {
            return $data;
        }

        foreach ($fields as $field) {
            if ($this->hasExplicitValue($field->key, $data)) {
                continue;
            }

            if ($this->isSequenceDefault($field)) {
                $data[$field->key] = $recordNumber;
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hasExplicitValue(string $key, array $data): bool
    {
        return array_key_exists($key, $data) && $data[$key] !== null;
    }

    private function resolvePreValidation(FieldDefinition $field): mixed
    {
        $default = $field->default_value;

        if (!is_array($default)) {
            return null;
        }

        return match ($default['kind'] ?? null) {
            'static' => $default['value'] ?? null,
            'dynamic' => $this->resolveDynamicSource($default['source'] ?? null),
            default => null,
        };
    }

    private function resolveDynamicSource(mixed $source): mixed
    {
        return match ($source) {
            'today' => Carbon::today()->format('Y-m-d'),
            'now' => Carbon::now()->format('Y-m-d H:i:s'),
            'current_user' => $this->auth->guard()->id(),
            default => null,
        };
    }

    private function isSequenceDefault(FieldDefinition $field): bool
    {
        $default = $field->default_value;

        return is_array($default)
            && ($default['kind'] ?? null) === 'dynamic'
            && ($default['source'] ?? null) === 'sequence';
    }
}
