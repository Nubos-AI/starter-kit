<?php

declare(strict_types=1);

namespace App\DTOs\Scim;

readonly class ScimUserChanges
{
    /**
     * @param  array{email?: string, first_name?: string|null, last_name?: string|null, is_active?: bool, external_id?: string|null}  $values
     */
    public function __construct(public array $values) {}

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }
}
