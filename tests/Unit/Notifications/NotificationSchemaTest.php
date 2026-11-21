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

it('removes the inbox of a user together with that user', function (): void {
    expect($this->inbox->hasForeignKey('notification_inbox', 'user_id', 'users', 'cascade'))->toBeTrue()
        ->and($this->inbox->hasForeignKey('notification_inbox', 'tenant_id', 'tenants', 'cascade'))->toBeTrue();
});

it('indexes the unread and unarchived inbox of one user and the snoozed rows', function (): void {
    expect($this->inbox->has('create index "idx_notification_inbox_scope" on "notification_inbox" ("tenant_id", "user_id", "archived_at", "read_at")'))->toBeTrue()
        ->and($this->inbox->has('create index "idx_notification_inbox_snooze" on "notification_inbox" ("user_id", "snoozed_until")'))->toBeTrue();
});

it('lets one user carry a single preference per type and channel', function (): void {
    expect($this->preferences->has('add constraint "unq_user_notification_preferences" unique ("user_id", "type", "channel")'))->toBeTrue();
});

it('leaves the segment reference of a rule without a foreign key so a deleted segment cannot drop the rule', function (): void {
    expect($this->rules->has('add constraint "notification_rules_segment_id_foreign"'))->toBeFalse();
});

it('lets one rule notify one user about one record and one stage exactly once', function (): void {
    expect($this->rules->has('add constraint "uniq_notification_rule_dispatches" unique ("rule_id", "record_id", "user_id", "stage")'))->toBeTrue();
});

it('pins the inbox, the preferences and the rules of a tenant to that tenant', function (): void {
    $tenant = AccessContext::tenant('notification-schema-tenant');
    $tenantId = (string) $tenant->getKey();

    expect(QueryShape::of(NotificationInbox::class)->isScopedToTenant('notification_inbox', $tenantId))->toBeTrue()
        ->and(QueryShape::of(UserNotificationPreference::class)->isScopedToTenant('user_notification_preferences', $tenantId))->toBeTrue()
        ->and(QueryShape::of(NotificationRule::class)->isScopedToTenant('notification_rules', $tenantId))->toBeTrue();
});
