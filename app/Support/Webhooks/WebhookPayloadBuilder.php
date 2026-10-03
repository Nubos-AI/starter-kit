<?php

declare(strict_types=1);

namespace App\Support\Webhooks;

use App\Enums\Webhooks\WebhookEventType;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\Role;
use App\Models\User;
use App\Models\WebhookSubscription;
use App\Support\Authorization\FieldPermissionMatrix;
use App\Support\CustomFields\EncryptedFieldKeys;
use Carbon\CarbonInterface;
use Closure;
use RuntimeException;

class WebhookPayloadBuilder
{
    /**
     * @param  (Closure(Role): list<array{objectType: array{id: string, key: string, slug: string, name: string}, fields: list<array{id: string, key: string, read: bool, write: bool}>}>)|null  $roleFieldMatrix
     */
    public function __construct(
        private readonly EncryptedFieldKeys $encryptedFieldKeys = new EncryptedFieldKeys,
        private readonly ?Closure $roleFieldMatrix = null,
    ) {}

    /**
     * @return array{
     *     event: array{
     *         type: string,
     *         occurredAt: string,
     *         objectType: string,
     *         recordId: string,
     *         sequence: int,
     *         version: int
     *     },
     *     data: array<string, mixed>
     * }
     */
    public function build(
        WebhookEventType $type,
        CustomRecord $record,
        WebhookSubscription $subscription,
        int $sequence,
        CarbonInterface $occurredAt,
    ): array {
        if ($record->tenant_id !== $subscription->tenant_id) {
            throw new RuntimeException(
                __('i18n.backend.support.webhooks.webhook_payload_builder.refusing_to_build_a_webhook_payload_the_record_belongs'),
            );
        }

        $this->resolveServiceUser($subscription);
        $role = $this->resolveRole($subscription);
        $objectType = $record->objectType;

        if (!$objectType instanceof ObjectType) {
            throw new RuntimeException(
                __('i18n.backend.support.webhooks.webhook_payload_builder.refusing_to_build_a_webhook_payload_the_record_has'),
            );
        }

        return [
            'event' => [
                'type' => $type->value,
                'occurredAt' => $occurredAt->toISOString(),
                'objectType' => $objectType->slug,
                'recordId' => $record->id,
                'sequence' => $sequence,
                'version' => $record->version,
            ],
            'data' => $this->visibleData($record, $role),
        ];
    }

    private function resolveServiceUser(WebhookSubscription $subscription): User
    {
        if ($subscription->service_user_id === null) {
            throw new RuntimeException(
                __('i18n.backend.support.webhooks.webhook_payload_builder.refusing_to_build_a_webhook_payload_the_subscription_has'),
            );
        }

        $serviceUser = $subscription->serviceUser;

        if (!$serviceUser instanceof User) {
            throw new RuntimeException(
                __('i18n.backend.support.webhooks.webhook_payload_builder.refusing_to_build_a_webhook_payload_the_bound_service'),
            );
        }

        return $serviceUser;
    }

    private function resolveRole(WebhookSubscription $subscription): Role
    {
        $role = $subscription->role;

        if (!$role instanceof Role) {
            throw new RuntimeException(
                __('i18n.backend.support.webhooks.webhook_payload_builder.refusing_to_build_a_webhook_payload_the_subscription_has_no_role'),
            );
        }

        return $role;
    }

    /**
     * @return array<string, mixed>
     */
    private function visibleData(CustomRecord $record, Role $role): array
    {
        $data = $record->data ?? [];

        $deliverable = array_diff(
            $this->readableFieldKeys($role, $record->object_type_id),
            $this->encryptedFieldKeys->forObjectType($record->object_type_id),
        );

        return array_intersect_key($data, array_flip($deliverable));
    }

    /**
     * @return list<string>
     */
    private function readableFieldKeys(Role $role, string $objectTypeId): array
    {
        $matrix = $this->roleFieldMatrix ?? FieldPermissionMatrix::forRole(...);

        foreach ($matrix($role) as $entry) {
            if ($entry['objectType']['id'] !== $objectTypeId) {
                continue;
            }

            $readable = array_filter(
                $entry['fields'],
                static fn (array $field): bool => $field['read'],
            );

            return array_values(array_map(static fn (array $field): string => $field['key'], $readable));
        }

        return [];
    }
}
