<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\Actions\Api\ProvisionServiceUserAction;
use App\Enums\Authorization\RoleScope;
use App\Enums\Webhooks\WebhookSubscriptionStatus;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\WebhookSubscription;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class CreateWebhookSubscriptionAction
{
    public function __construct(
        private readonly ProvisionServiceUserAction $provisionServiceUser,
        private readonly ActivateSubscriptionViaChallengeAction $activateSubscription,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{subscription: WebhookSubscription, secret: string, status: WebhookSubscriptionStatus}
     */
    public function execute(Tenant $tenant, array $input): array
    {
        $validated = Validator::make(
            $input,
            [
                'name' => ['required', 'string', 'max:255'],
                'target_url' => ['required', 'url', 'max:2048'],
                'auth_username' => ['nullable', 'string', 'max:255'],
                'auth_password' => ['nullable', 'string', 'max:255', 'required_with:auth_username'],
                'roleName' => ['required', 'string', $this->integrationRoleRule($tenant)],
                'event_types' => ['required', 'array', 'min:1'],
                'event_types.*' => ['required', Rule::in(config('webhooks.events', []))],
                'object_type_id' => ['nullable', 'string', Rule::exists('object_types', 'id')->where('tenant_id', (string) $tenant->getKey())],
            ]
        )->validate();

        $serviceUser = $this->provisionServiceUser->execute($tenant);
        $role = $this->integrationRole($validated['roleName']);

        $secret = Str::random(64);

        $username = $validated['auth_username'] ?? null;

        $subscription = WebhookSubscription::query()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => $validated['name'],
            'target_url' => $validated['target_url'],
            'auth_username' => $username,
            'auth_password' => $username === null ? null : ($validated['auth_password'] ?? null),
            'secret' => $secret,
            'status' => WebhookSubscriptionStatus::Pending,
            'service_user_id' => $serviceUser->getKey(),
            'role_id' => $role->getKey(),
            'event_types' => array_values($validated['event_types']),
            'object_type_id' => $validated['object_type_id'] ?? null,
        ]);

        $status = $this->activateSubscription->execute($subscription);

        return ['subscription' => $subscription, 'secret' => $secret, 'status' => $status];
    }

    private function integrationRole(string $roleName): Role
    {
        return Role::query()
            ->where('name', $roleName)
            ->where('scope', RoleScope::Tenant)
            ->whereNull('authority')
            ->firstOrFail();
    }

    private function integrationRoleRule(Tenant $tenant): Exists
    {
        return Rule::exists('roles', 'name')->where(
            fn (Builder $query): Builder => $query
                ->where('scope', RoleScope::Tenant->value)
                ->whereNull('authority')
                ->where('tenant_id', (string) $tenant->getKey()),
        );
    }
}
