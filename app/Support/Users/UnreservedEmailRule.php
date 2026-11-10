<?php

declare(strict_types=1);

namespace App\Support\Users;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UnreservedEmailRule implements ValidationRule
{
    public function __construct(private readonly ServiceAccountEmail $serviceAccounts = new ServiceAccountEmail) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && $this->serviceAccounts->isReserved($value)) {
            $fail(__('i18n.backend.support.users.unreserved_email_rule.this_email_domain_is_reserved'));
        }
    }
}
