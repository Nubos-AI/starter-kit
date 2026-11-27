<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Models\FieldDefinition;
use Illuminate\Support\Facades\DB;
use Throwable;

class AppendFieldOptionsAction
{
    /**
     * @param  list<string>  $values
     *
     * @throws Throwable
     */
    public function execute(FieldDefinition $field, array $values): void
    {
        if ($values === []) {
            return;
        }

        DB::transaction(function () use ($field, $values): void {
            $fresh = FieldDefinition::query()
                ->whereKey($field->getKey())
                ->lockForUpdate()
                ->first();

            if (!$fresh instanceof FieldDefinition) {
                return;
            }

            $config = $fresh->config ?? [];
            $existing = is_array($config['options'] ?? null) ? array_values($config['options']) : [];

            $known = array_map(static fn (mixed $option): string => is_scalar($option) ? (string) $option : '', $existing);

            foreach ($values as $value) {
                if (!in_array($value, $known, true)) {
                    $existing[] = $value;
                    $known[] = $value;
                }
            }

            $config['options'] = $existing;
            $fresh->config = $config;
            $fresh->save();
        });
    }
}
