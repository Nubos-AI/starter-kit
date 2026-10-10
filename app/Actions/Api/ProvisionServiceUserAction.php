<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

class ProvisionServiceUserAction
{
    public function execute(Tenant $tenant): User
    {
        return User::query()->firstOrCreate(
            [
                'email' => $this->serviceEmailFor($tenant),
            ],
            [
                'tenant_id' => $tenant->getKey(),
                'is_service' => true,
                'first_name' => 'API',
                'last_name' => __('i18n.backend.actions.api.provision_service_user_action.service'),
                'password' => Str::random(64),
                'email_verified_at' => now(),
            ]
        );
    }

    private function serviceEmailFor(Tenant $tenant): string
    {
        return 'service-'.Str::lower((string) $tenant->getKey()).'@service.invalid';
    }
}
