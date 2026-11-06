<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Actions\Webhooks\ActivateSubscriptionViaChallengeAction;
use App\Actions\Webhooks\BulkDeleteWebhookSubscriptionsAction;
use App\Actions\Webhooks\CreateWebhookSubscriptionAction;
use App\Actions\Webhooks\DeleteWebhookSubscriptionAction;
use App\Actions\Webhooks\RotateWebhookSecretAction;
use App\Actions\Webhooks\UpdateWebhookSubscriptionAction;
use App\Enums\Authorization\RoleScope;
use App\Enums\Ui\ToastType;
use App\Enums\Webhooks\WebhookEventType;
use App\Enums\Webhooks\WebhookSubscriptionStatus;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\ObjectType;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookSubscription;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class WebhookSubscriptionsController extends Controller
{
    public function __construct(
        private readonly CreateWebhookSubscriptionAction $createSubscription,
        private readonly UpdateWebhookSubscriptionAction $updateSubscription,
        private readonly RotateWebhookSecretAction $rotateSecret,
        private readonly DeleteWebhookSubscriptionAction $deleteSubscription,
        private readonly BulkDeleteWebhookSubscriptionsAction $bulkDeleteSubscriptions,
        private readonly ActivateSubscriptionViaChallengeAction $activateSubscription,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $tenant = $this->authorizedTenant($request);

        return Inertia::render('webhooks/Index', [
            'subscriptions' => $this->subscriptionRows($tenant),
        ]);
    }

    public function create(Request $request): InertiaResponse
    {
        $this->authorizedTenant($request);

        return Inertia::render('webhooks/Form', [
            'mode' => 'create',
            'subscription' => null,
            'secret' => null,
            ...$this->formOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->authorizedTenant($request);

        $result = $this->createSubscription->execute($tenant, $request->all());

        return to_route('engine.webhooks.edit', ['subscription' => $result['subscription']->getKey()])
            ->with('webhookSecret', $result['secret']);
    }

    public function edit(Request $request, string $subscription): InertiaResponse
    {
        $tenant = $this->authorizedTenant($request);

        $model = $this->tenantSubscriptionOrFail($tenant, $subscription);
        $secret = $request->session()->get('webhookSecret');

        return Inertia::render('webhooks/Form', [
            'mode' => 'edit',
            'subscription' => $this->formPayload($model),
            'secret' => is_string($secret) ? $secret : null,
            ...$this->formOptions(),
        ]);
    }

    public function update(Request $request, string $subscription): RedirectResponse
    {
        $tenant = $this->authorizedTenant($request);

        $this->updateSubscription->execute(
            $this->tenantSubscriptionOrFail($tenant, $subscription),
            $request->all(),
        );

        return to_route('engine.webhooks.edit', ['subscription' => $subscription]);
    }

    public function rotateSecret(Request $request, string $subscription): RedirectResponse
    {
        $tenant = $this->authorizedTenant($request);

        $secret = $this->rotateSecret->execute($this->tenantSubscriptionOrFail($tenant, $subscription));

        return to_route('engine.webhooks.edit', ['subscription' => $subscription])
            ->with('webhookSecret', $secret);
    }

    public function recheck(Request $request, string $subscription): RedirectResponse
    {
        $tenant = $this->authorizedTenant($request);

        $status = $this->activateSubscription->execute(
            $this->tenantSubscriptionOrFail($tenant, $subscription),
        );

        Inertia::flash('toast', $status === WebhookSubscriptionStatus::Active
            ? ['type' => ToastType::Success->value, 'message' => __('i18n.backend.http.controllers.webhooks.webhook_subscriptions_controller.the_destination_url_confirmed_the_challenge')]
            : ['type' => ToastType::Warning->value, 'message' => __('i18n.backend.http.controllers.webhooks.webhook_subscriptions_controller.the_destination_url_did_not_confirm_the_challenge')]);

        return to_route('engine.webhooks.edit', ['subscription' => $subscription]);
    }

    public function destroy(Request $request, string $subscription): RedirectResponse
    {
        $tenant = $this->authorizedTenant($request);

        $this->deleteSubscription->execute($this->tenantSubscriptionOrFail($tenant, $subscription));

        return to_route('engine.webhooks.index');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->authorizedTenant($request);

        $this->bulkDeleteSubscriptions->execute($this->actingUser($request), $request->all());

        return to_route('engine.webhooks.index');
    }

    private function tenantSubscriptionOrFail(Tenant $tenant, string $subscription): WebhookSubscription
    {
        return WebhookSubscription::query()
            ->where('tenant_id', $tenant->getKey())
            ->findOrFail($subscription);
    }

    private function authorizedTenant(Request $request): Tenant
    {
        $user = $request->user();

        if (!$user instanceof User || !$user->isEscalatedAuthority()) {
            throw new AuthorizationException(__('i18n.backend.http.controllers.webhooks.webhook_subscriptions_controller.you_may_not_manage_webhook_subscriptions'));
        }

        $tenant = $user->tenant;

        if (!$tenant instanceof Tenant) {
            throw new AuthorizationException(__('i18n.backend.http.controllers.webhooks.webhook_subscriptions_controller.managing_webhook_subscriptions_requires_a_tenant_context'));
        }

        return $tenant;
    }

    /**
     * @return array{eventTypeOptions: array<int, array{value: string, label: string}>, objectTypeOptions: array<int, array{value: string, label: string}>, roleOptions: array<int, array{value: string, label: string}>}
     */
    private function formOptions(): array
    {
        return [
            'eventTypeOptions' => $this->eventTypeOptions(),
            'objectTypeOptions' => $this->objectTypeOptions(),
            'roleOptions' => $this->roleOptions(),
        ];
    }

    /**
     * @return array<int, array{id: string, name: string, targetUrl: string, status: string, eventTypes: list<string>, objectType: string|null, consecutiveFailures: int, lastError: string|null, activatedAt: string|null, rotatedAt: string|null, createdAt: string|null}>
     */
    private function subscriptionRows(Tenant $tenant): array
    {
        return WebhookSubscription::query()
            ->with('objectType')
            ->where('tenant_id', $tenant->getKey())
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (WebhookSubscription $subscription): array => [
                'id' => (string) $subscription->getKey(),
                'name' => $subscription->name,
                'targetUrl' => $subscription->target_url,
                'status' => $subscription->status->value,
                'eventTypes' => $this->eventTypeValues($subscription),
                'objectType' => $subscription->objectType?->name,
                'consecutiveFailures' => $subscription->consecutive_failures,
                'lastError' => $subscription->last_error,
                'activatedAt' => $subscription->activated_at?->toIso8601String(),
                'rotatedAt' => $subscription->rotated_at?->toIso8601String(),
                'createdAt' => $subscription->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array{id: string, name: string, targetUrl: string, authUsername: string|null, status: string, eventTypes: list<string>, objectTypeId: string|null, roleName: string|null, consecutiveFailures: int, lastError: string|null, activatedAt: string|null, rotatedAt: string|null}
     */
    private function formPayload(WebhookSubscription $subscription): array
    {
        return [
            'id' => (string) $subscription->getKey(),
            'name' => $subscription->name,
            'targetUrl' => $subscription->target_url,
            'authUsername' => $subscription->auth_username,
            'status' => $subscription->status->value,
            'eventTypes' => $this->eventTypeValues($subscription),
            'objectTypeId' => $subscription->object_type_id,
            'roleName' => $subscription->role?->name,
            'consecutiveFailures' => $subscription->consecutive_failures,
            'lastError' => $subscription->last_error,
            'activatedAt' => $subscription->activated_at?->toIso8601String(),
            'rotatedAt' => $subscription->rotated_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<string>
     */
    private function eventTypeValues(WebhookSubscription $subscription): array
    {
        return array_values($subscription->event_types
            ->map(fn (WebhookEventType $type): string => $type->value)
            ->all());
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function eventTypeOptions(): array
    {
        return collect(WebhookEventType::cases())
            ->filter(fn (WebhookEventType $type): bool => in_array($type->value, config('webhooks.events', []), true))
            ->map(fn (WebhookEventType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function objectTypeOptions(): array
    {
        return ObjectType::query()
            ->orderBy('name')
            ->get()
            ->map(fn (ObjectType $objectType): array => [
                'value' => (string) $objectType->getKey(),
                'label' => $objectType->name,
            ])
            ->all();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function roleOptions(): array
    {
        return Role::query()
            ->where('scope', RoleScope::Tenant)
            ->whereNull('authority')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role): array => [
                'value' => $role->name,
                'label' => $role->name,
            ])
            ->all();
    }
}
