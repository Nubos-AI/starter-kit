<?php

declare(strict_types=1);

namespace App\Traits\Engine;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

trait ValidatesTypeName
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    private function validatedTypeName(array $input, string $table, ?string $ignoreId = null): string
    {
        $unique = Rule::unique($table, 'name')
            ->where('tenant_id', TenantContext::currentId())
            ->whereNull('deleted_at');

        if ($ignoreId !== null) {
            $unique->ignore($ignoreId);
        }

        $validated = Validator::make(
            $input,
            [
                'name' => ['required', 'string', 'max:255', $unique],
            ]
        )->validate();

        return (string) $validated['name'];
    }
}
