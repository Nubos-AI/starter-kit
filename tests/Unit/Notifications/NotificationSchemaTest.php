<?php

declare(strict_types=1);

use App\Models\NotificationInbox;
use App\Models\NotificationRule;
use App\Models\UserNotificationPreference;
use Tests\Support\AccessContext;
use Tests\Support\QueryShape;
use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->inbox = SchemaShape::ofMigration('database/migrations/0001_01_01_000026_create_notification_inbox_table.php');
    $this->preferences = SchemaShape::ofMigration('database/migrations/0001_01_01_000028_create_user_notification_preferences_table.php');
    $this->rules = SchemaShape::ofMigration('database/migrations/0001_01_01_000035_create_notification_rules_table.php');
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('creates the inbox columns in the order the conventions demand', function (): void {
    expect($this->inbox->columnsOf('notification_inbox'))->toBe([
        'id',
        'tenant_id',
        'user_id',
        'type',
        'data',
        'priority',
        'read_at',
        'snoozed_until',
        'archived_at',
        'created_at',
        'updated_at',
    ]);
});

it('removes the inbox of a user together with that user', function (): void {
    expect($this->inbox->hasForeignKey('notification_inbox', 'user_id', 'users', 'cascade'))->toBeTrue()
        ->and($this->inbox->hasForeignKey('notification_inbox', 'tenant_id', 'tenants', 'cascade'))->toBeTrue();
});

it('indexes the unread and unarchived inbox of one user and the snoozed rows', function (): void {
    expect($this->inbox->has('create index "idx_notification_inbox_scope" on "notification_inbox" ("tenant_id", "user_id", "archived_at", "read_at")'))->toBeTrue()
        ->and($this->inbox->has('create index "idx_notification_inbox_snooze" on "notification_inbox" ("user_id", "snoozed_until")'))->toBeTrue();
});

it('lets one user carry a single preference per type and channel', function (): void {
    expect($this->preferences->columnsOf('user_notification_preferences'))->toBe([
        'id',
        'tenant_id',
        'user_id',
        'type',
        'channel',
        'enabled',
        'delivery_mode',
        'created_at',
        'updated_at',
    ])
        ->and($this->preferences->has('add constraint "unq_user_notification_preferences" unique ("user_id", "type", "channel")'))->toBeTrue();
});

it('creates the rule columns in the order the conventions demand', function (): void {
    expect($this->rules->columnsOf('notification_rules'))->toBe([
        'id',
        'tenant_id',
        'object_type_id',
        'name',
        'trigger_type',
        'config',
        'segment_id',
        'filter_definition',
        'action',
        'is_active',
        'created_by_id',
        'created_at',
        'updated_at',
    ]);
});

it('leaves the segment reference of a rule without a foreign key so a deleted segment cannot drop the rule', function (): void {
    expect($this->rules->createStatementFor('notification_rules'))->toContain('"segment_id" char(26) null')
        ->and($this->rules->has('add constraint "notification_rules_segment_id_foreign"'))->toBeFalse();
});

it('lets one rule notify one user about one record and one stage exactly once', function (): void {
    expect($this->rules->has('add constraint "uniq_notification_rule_dispatches" unique ("rule_id", "record_id", "user_id", "stage")'))->toBeTrue();
});

it('drops both rule tables again on the way down, the dispatches first', function (): void {
    $down = SchemaShape::ofMigration('database/migrations/0001_01_01_000035_create_notification_rules_table.php', 'down');

    expect($down->statements)->toBe([
        'drop table if exists "notification_rule_dispatches"',
        'drop table if exists "notification_rules"',
    ]);
});

it('pins the inbox, the preferences and the rules of a tenant to that tenant', function (): void {
    $tenant = AccessContext::tenant('notification-schema-tenant');
    $tenantId = (string) $tenant->getKey();

    expect(QueryShape::of(NotificationInbox::class)->isScopedToTenant('notification_inbox', $tenantId))->toBeTrue()
        ->and(QueryShape::of(UserNotificationPreference::class)->isScopedToTenant('user_notification_preferences', $tenantId))->toBeTrue()
        ->and(QueryShape::of(NotificationRule::class)->isScopedToTenant('notification_rules', $tenantId))->toBeTrue();
});

it('blocks every inbox row when no tenant is bound', function (): void {
    AccessContext::forgetTenant();

    expect(QueryShape::of(NotificationInbox::class)->blocksEveryRow())->toBeTrue();
});
