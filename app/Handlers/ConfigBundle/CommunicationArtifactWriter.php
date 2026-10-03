<?php

declare(strict_types=1);

namespace App\Handlers\ConfigBundle;

use App\Actions\Notifications\DeleteNotificationRuleAction;
use App\Actions\Notifications\SaveNotificationRuleAction;
use App\Actions\Notifications\UpdateNotificationPreferencesAction;
use App\Actions\Webhooks\CreateWebhookSubscriptionAction;
use App\Actions\Webhooks\DeleteWebhookSubscriptionAction;
use App\Actions\Webhooks\UpdateWebhookSubscriptionAction;
use App\Contracts\ConfigBundle\ArtifactWriterInterface;
use App\DTOs\ConfigBundle\ArtifactWriteResult;
use App\DTOs\ConfigBundle\BundleArtifact;
use App\Enums\Authorization\RoleScope;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\ConfigBundle\ArtifactWriteAction;
use App\Enums\Notifications\DeliveryMode;
use App\Enums\Promotion\DiffState;
use App\Enums\Webhooks\WebhookSubscriptionStatus;
use App\Models\NotificationRule;
use App\Models\NotificationTypeDefault;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookSubscription;
use App\Support\ConfigBundle\BundleWriteTenant;
use App\Support\ConfigBundle\TargetKeyResolver;
use App\Support\Tenancy\TenantContext;
use App\Traits\ConfigBundle\ReportsArtifactWriteResults;
use App\Traits\ConfigBundle\ResolvesArtifactTargets;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CommunicationArtifactWriter implements ArtifactWriterInterface
{
    use ReportsArtifactWriteResults;
    use ResolvesArtifactTargets;

    public function __construct(
        private readonly BundleWriteTenant $writeTenant,
        private readonly CreateWebhookSubscriptionAction $createSubscription,
        private readonly DeleteNotificationRuleAction $deleteRule,
        private readonly DeleteWebhookSubscriptionAction $deleteSubscription,
        private readonly SaveNotificationRuleAction $saveRule,
        private readonly TargetKeyResolver $targetKeys,
        private readonly UpdateNotificationPreferencesAction $updatePreferences,
        private readonly UpdateWebhookSubscriptionAction $updateSubscription,
    ) {}

    public function supports(ArtifactKind $kind): bool
    {
        return match ($kind) {
            ArtifactKind::NotificationRules,
            ArtifactKind::NotificationTypeDefaults,
            ArtifactKind::WebhookSubscriptions => true,
            default => false,
        };
    }

    /**
     * @throws AuthorizationException
     */
    public function apply(User $actingUser, ArtifactKind $kind, BundleArtifact $artifact, DiffState $state): ArtifactWriteResult
    {
        $tenant = $this->assertedTargetTenant($actingUser);

        if ($state !== DiffState::Added && $state !== DiffState::Modified) {
            return $this->skippedByState($kind, $artifact->key, $state);
        }

        if ($this->carriesPlaceholder($artifact->payload)) {
            return $this->skippedByPlaceholder($kind, $artifact->key);
        }

        $tenantId = (string) $tenant->getKey();

        $result = match ($kind) {
            ArtifactKind::NotificationRules => $this->applyRule($actingUser, $tenantId, $artifact),
            ArtifactKind::NotificationTypeDefaults => $this->applyTypeDefault($actingUser, $artifact),
            ArtifactKind::WebhookSubscriptions => $this->applySubscription($tenant, $artifact),
            default => $this->unsupported($kind, $artifact->key),
        };

        return $this->invalidated($kind, $result);
    }

    /**
     * @throws AuthorizationException
     */
    public function remove(User $actingUser, ArtifactKind $kind, string $key): ArtifactWriteResult
    {
        $tenant = $this->assertedTargetTenant($actingUser);

        $tenantId = (string) $tenant->getKey();

        $result = match ($kind) {
            ArtifactKind::NotificationRules => $this->removeRule($tenantId, $key),
            ArtifactKind::NotificationTypeDefaults => $this->skipped(
                $kind,
                $key,
                __('i18n.backend.handlers.config_bundle.communication_artifact_writer.there_is_no_deletion_path_for_channel_defaults_the'),
            ),
            ArtifactKind::WebhookSubscriptions => $this->removeSubscription($tenant, $key),
            default => $this->unsupported($kind, $key),
        };

        return $this->invalidated($kind, $result);
    }

    /**
     * @return list<ArtifactWriteResult>
     */
    public function flush(User $actingUser): array
    {
        return [];
    }

    /**
     * @throws AuthorizationException
     */
    private function assertedTargetTenant(User $actingUser): Tenant
    {
        $tenant = TenantContext::current();

        if (!$tenant instanceof Tenant) {
            throw new AuthorizationException(__('i18n.backend.handlers.config_bundle.communication_artifact_writer.bundle_artifacts_can_only_be_written_into_a_bound'));
        }

        $writeTenantId = $this->writeTenant->tenantIdFor($actingUser);

        if ($writeTenantId === null || (string) $tenant->getKey() !== $writeTenantId) {
            throw new AuthorizationException(__('i18n.backend.handlers.config_bundle.communication_artifact_writer.the_bound_target_tenant_must_be_the_tenant_of'));
        }

        return $tenant;
    }

    private function applyRule(User $actingUser, string $tenantId, BundleArtifact $artifact): ArtifactWriteResult
    {
        $kind = ArtifactKind::NotificationRules;
        $payload = $artifact->payload;
        $components = explode(':', $artifact->key, 3);
        $objectTypeId = $this->targetKeys->parentIdFor($kind, $artifact->key, 'object_type_id');

        if ($objectTypeId === null) {
            return $this->unresolvable($kind, $artifact->key, 'object_type_id', $components[0]);
        }

        $segmentKey = $payload['segment_id'] ?? null;
        $segmentId = null;

        if (is_string($segmentKey) && $segmentKey !== '') {
            $segmentId = $this->targetKeys->idFor(ArtifactKind::Segments, $segmentKey);

            if ($segmentId === null) {
                return $this->unresolvable($kind, $artifact->key, 'segment_id', $segmentKey);
            }
        }

        $data = [
            'name' => $components[2] ?? '',
            'trigger_type' => $components[1] ?? '',
            'config' => $this->resolvedDeeply($payload['config'] ?? null),
            'segment_id' => $segmentId,
            'filter_definition' => $this->resolvedDeeply($payload['filter_definition'] ?? null),
            'action' => $this->resolvedDeeply($payload['action'] ?? null),
            'is_active' => $payload['is_active'] ?? true,
        ];

        $existing = $this->targetRow($kind, $artifact->key, NotificationRule::class, $tenantId);

        if ($existing instanceof ArtifactWriteResult) {
            return $existing;
        }

        if (!$existing instanceof NotificationRule) {
            return $this->written(
                $kind,
                $artifact->key,
                ArtifactWriteAction::Created,
                $this->saveRule->create(['object_type_id' => $objectTypeId, ...$data], $actingUser),
            );
        }

        $this->saveRule->update($existing, $data, $actingUser);

        return $this->written($kind, $artifact->key, ArtifactWriteAction::Updated, $existing);
    }

    private function removeRule(string $tenantId, string $key): ArtifactWriteResult
    {
        $kind = ArtifactKind::NotificationRules;
        $rule = $this->targetRow($kind, $key, NotificationRule::class, $tenantId);

        if ($rule instanceof ArtifactWriteResult) {
            return $rule;
        }

        if (!$rule instanceof NotificationRule) {
            return $this->missingTarget($kind, $key);
        }

        $this->deleteRule->execute($rule);

        return $this->written($kind, $key, ArtifactWriteAction::Removed, $rule);
    }

    private function applyTypeDefault(User $actingUser, BundleArtifact $artifact): ArtifactWriteResult
    {
        $kind = ArtifactKind::NotificationTypeDefaults;
        $payload = $artifact->payload;
        $separator = mb_strrpos($artifact->key, ':');

        if ($separator === false) {
            return $this->skipped($kind, $artifact->key, __('i18n.backend.handlers.config_bundle.communication_artifact_writer.the_channel_default_s_key_specifies_no_channel_nothing'));
        }

        $existingId = $this->targetKeys->idFor($kind, $artifact->key);

        $written = $this->updatePreferences->writeTenantDefaults($actingUser, [
            'defaults' => [
                [
                    'type' => mb_substr($artifact->key, 0, $separator),
                    'channel' => mb_substr($artifact->key, $separator + 1),
                    'enabled' => $payload['enabled'] ?? true,
                    'delivery_mode' => $payload['delivery_mode'] ?? DeliveryMode::Immediate->value,
                ],
            ],
        ]);

        $default = $written[0] ?? null;

        if (!$default instanceof NotificationTypeDefault) {
            return $this->skipped($kind, $artifact->key, __('i18n.backend.handlers.config_bundle.communication_artifact_writer.the_target_tenant_returned_no_row_for_this_channel'));
        }

        return $this->written(
            $kind,
            $artifact->key,
            $existingId === null ? ArtifactWriteAction::Created : ArtifactWriteAction::Updated,
            $default,
        );
    }

    private function applySubscription(Tenant $tenant, BundleArtifact $artifact): ArtifactWriteResult
    {
        $kind = ArtifactKind::WebhookSubscriptions;
        $payload = $artifact->payload;
        $targetUrl = $payload['target_url'] ?? null;

        if (!is_string($targetUrl) || !Str::startsWith($targetUrl, 'https://')) {
            return $this->skipped($kind, $artifact->key, __('i18n.backend.handlers.config_bundle.communication_artifact_writer.the_subscription_destination_is_not_a_secure_https_address'));
        }

        $objectTypeKey = $payload['object_type_id'] ?? null;
        $objectTypeId = null;

        if (is_string($objectTypeKey) && $objectTypeKey !== '') {
            $objectTypeId = $this->targetKeys->idFor(ArtifactKind::ObjectTypes, $objectTypeKey);

            if ($objectTypeId === null) {
                return $this->unresolvable($kind, $artifact->key, 'object_type_id', $objectTypeKey);
            }
        }

        $eventTypes = $payload['event_types'] ?? null;

        if (!is_array($eventTypes) || $eventTypes === []) {
            return $this->skipped($kind, $artifact->key, __('i18n.backend.handlers.config_bundle.communication_artifact_writer.the_subscription_specifies_no_events_nothing_is_written_to'));
        }

        $existing = $this->targetRow($kind, $artifact->key, WebhookSubscription::class, (string) $tenant->getKey());

        if ($existing instanceof ArtifactWriteResult) {
            return $existing;
        }

        if ($existing instanceof WebhookSubscription) {
            $status = $this->updateSubscription->execute($existing, [
                'name' => $artifact->key,
                'target_url' => $targetUrl,
                'auth_username' => $existing->auth_username,
                'event_types' => array_values($eventTypes),
                'object_type_id' => $objectTypeId,
            ]);

            return $this->written($kind, $artifact->key, ArtifactWriteAction::Updated, $existing, $this->challengeNotes($status));
        }

        $role = $this->integrationRoleFor($payload['role_id'] ?? null);

        if (!$role instanceof Role) {
            return $this->skipped($kind, $artifact->key, __('i18n.backend.handlers.config_bundle.communication_artifact_writer.the_specified_integration_role_is_missing_in_the_target'));
        }

        $created = $this->createSubscription->execute($tenant, [
            'name' => $artifact->key,
            'target_url' => $targetUrl,
            'roleName' => $role->name,
            'event_types' => array_values($eventTypes),
            'object_type_id' => $objectTypeId,
        ]);

        return $this->written(
            $kind,
            $artifact->key,
            ArtifactWriteAction::Created,
            $created['subscription'],
            $this->challengeNotes($created['status']),
        );
    }

    private function removeSubscription(Tenant $tenant, string $key): ArtifactWriteResult
    {
        $kind = ArtifactKind::WebhookSubscriptions;
        $subscription = $this->targetRow($kind, $key, WebhookSubscription::class, (string) $tenant->getKey());

        if ($subscription instanceof ArtifactWriteResult) {
            return $subscription;
        }

        if (!$subscription instanceof WebhookSubscription) {
            return $this->missingTarget($kind, $key);
        }

        $this->deleteSubscription->execute($subscription);

        return $this->written($kind, $key, ArtifactWriteAction::Removed, $subscription);
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function targetRow(ArtifactKind $kind, string $key, string $modelClass, string $tenantId): ArtifactWriteResult|Model|null
    {
        if ($this->matchingRows($kind, $key, $tenantId) > 1) {
            return $this->ambiguous($kind, $key);
        }

        $id = $this->targetKeys->idFor($kind, $key);

        if ($id === null) {
            return null;
        }

        return $modelClass::query()->whereKey($id)->first();
    }

    private function matchingRows(ArtifactKind $kind, string $key, string $tenantId): int
    {
        if ($kind === ArtifactKind::WebhookSubscriptions) {
            return WebhookSubscription::query()
                ->where('tenant_id', $tenantId)
                ->where('name', $key)
                ->count();
        }

        $objectTypeId = $this->targetKeys->parentIdFor($kind, $key, 'object_type_id');

        if ($objectTypeId === null) {
            return 0;
        }

        if ($kind !== ArtifactKind::NotificationRules) {
            return 0;
        }

        $components = explode(':', $key, 3);

        return NotificationRule::query()
            ->where('tenant_id', $tenantId)
            ->where('object_type_id', $objectTypeId)
            ->where('trigger_type', $components[1] ?? '')
            ->where('name', $components[2] ?? '')
            ->count();
    }

    private function integrationRoleFor(mixed $roleKey): ?Role
    {
        if (!is_string($roleKey) || $roleKey === '') {
            return null;
        }

        $id = $this->targetKeys->idFor(ArtifactKind::Roles, $roleKey);

        if ($id === null) {
            return null;
        }

        return Role::query()
            ->whereKey($id)
            ->where('scope', RoleScope::Tenant)
            ->whereNull('authority')
            ->first();
    }

    /**
     * @return list<string>
     */
    private function challengeNotes(WebhookSubscriptionStatus $status): array
    {
        return $status === WebhookSubscriptionStatus::Pending
            ? [__('i18n.backend.handlers.config_bundle.communication_artifact_writer.the_remote_endpoint_has_not_confirmed_the_new_signing')]
            : [];
    }
}
