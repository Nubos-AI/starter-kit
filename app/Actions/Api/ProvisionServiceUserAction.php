<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Users\ServiceAccountEmail;
use Illuminate\Support\Str;

class ProvisionServiceUserAction
{
    public function __construct(private readonly ServiceAccountEmail $serviceAccounts) {}

    public function execute(Tenant $tenant): User
    {
        $serviceUser = User::query()->firstOrNew(
            [
                'tenant_id' => $tenant->getKey(),
                'is_service' => true,
            ],
            [
                'email' => $this->serviceAccounts->generate(),
                'first_name' => 'API',
                'last_name' => __('i18n.backend.actions.api.provision_service_user_action.service'),
                'password' => Str::random(64),
            ]
        );

        if ($serviceUser->exists) {
            return $serviceUser;
        }

        $serviceUser->forceFill(['email_verified_at' => now()])->save();

        return $serviceUser;
    }
}
