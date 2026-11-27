<?php

declare(strict_types=1);

use App\Enums\Webhooks\WebhookEventType;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\Role;
use App\Models\User;
use App\Models\WebhookSubscription;
use App\Support\CustomFields\EncryptedFieldKeys;
use App\Support\Webhooks\WebhookPayloadBuilder;
use Carbon\CarbonImmutable;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->objectTypeId = ModelStub::ulid('companies');
    $this->occurredAt = CarbonImmutable::createFromTimestamp(1700000000, 'UTC');

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => $this->objectTypeId,
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    $this->role = ModelStub::make(Role::class, [
        'id' => ModelStub::ulid('subscription-role'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Webhook',
    ]);

    $this->serviceUser = ModelStub::make(User::class, [
        'id' => ModelStub::ulid('webhook-service-user'),
        'tenant_id' => $this->tenant->getKey(),
    ]);

    /** @var callable(array<string, mixed>, array<string, mixed>):WebhookSubscription */
    $this->subscription = fn (array $attributes = [], array $relations = []): WebhookSubscription => ModelStub::make(
        WebhookSubscription::class,
        [
            'id' => ModelStub::ulid('subscription'),
            'tenant_id' => $this->tenant->getKey(),
            'service_user_id' => $this->serviceUser->getKey(),
            'role_id' => $this->role->getKey(),
            'object_type_id' => $this->objectTypeId,
            ...$attributes,
        ],
        [
            'serviceUser' => $this->serviceUser,
            'role' => $this->role,
            ...$relations,
        ],
    );

    /** @var callable(array<string, mixed>, ?string):CustomRecord */
    $this->record = fn (array $data, ?string $tenantId = null): CustomRecord => ModelStub::make(
        CustomRecord::class,
        [
            'id' => ModelStub::ulid('company-record'),
            'tenant_id' => $tenantId ?? $this->tenant->getKey(),
            'object_type_id' => $this->objectTypeId,
            'version' => 4,
            'data' => $data,
        ],
        ['objectType' => $this->objectType],
    );

    /** @var callable(list<array{key: string, read: bool}>, ?Role):WebhookPayloadBuilder */
    $this->builderGranting = function (array $fields, ?Role $expectedRole = null): WebhookPayloadBuilder {
        $objectTypeId = $this->objectTypeId;

        return new WebhookPayloadBuilder(
            new class extends EncryptedFieldKeys
            {
                /**
                 * @return list<string>
                 */
                public function forObjectType(string $objectTypeId): array
                {
                    return ['secret'];
                }
            },
            function (Role $role) use ($fields, $objectTypeId, $expectedRole): array {
                if ($expectedRole !== null && $role->getKey() !== $expectedRole->getKey()) {
                    return [];
                }

                return [[
                    'objectType' => ['id' => $objectTypeId, 'key' => 'companies', 'slug' => 'companies', 'name' => 'Companies'],
                    'fields' => array_map(
                        static fn (array $field): array => [
                            'id' => ModelStub::ulid($field['key']),
                            'key' => $field['key'],
                            'read' => $field['read'],
                            'write' => false,
                        ],
                        $fields,
                    ),
                ]];
            },
        );
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('delivers only the fields the role of the subscription may read', function (): void {
    $builder = ($this->builderGranting)([
        ['key' => 'name', 'read' => true],
        ['key' => 'salary', 'read' => false],
        ['key' => 'city', 'read' => true],
    ]);

    $payload = $builder->build(
        WebhookEventType::RecordUpdated,
        ($this->record)(['name' => 'Meier GmbH', 'salary' => 90000, 'city' => 'Karlsruhe']),
        ($this->subscription)(),
        1,
        $this->occurredAt,
    );

    expect($payload['data'])->toBe(['name' => 'Meier GmbH', 'city' => 'Karlsruhe']);
});

it('refuses to build a payload for a record of another tenant', function (): void {
    $builder = ($this->builderGranting)([['key' => 'name', 'read' => true]]);
    $foreign = ($this->record)(['name' => 'Meier GmbH'], ModelStub::ulid('other-tenant'));

    expect(fn (): array => $builder->build(WebhookEventType::RecordUpdated, $foreign, ($this->subscription)(), 1, $this->occurredAt))
        ->toThrow(RuntimeException::class);
});
