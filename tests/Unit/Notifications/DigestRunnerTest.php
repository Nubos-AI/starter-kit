<?php

declare(strict_types=1);

use App\Mail\NotificationDigestMail;
use App\Support\Notifications\DigestRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenantId = ModelStub::ulid('digest-runner-tenant');
    $this->userId = ModelStub::ulid('digest-runner-user');

    $this->inboxEntry = fn (string $type): array => [
        'id' => ModelStub::ulid("digest-runner-inbox-{$type}"),
        'tenant_id' => $this->tenantId,
        'user_id' => $this->userId,
        'type' => $type,
        'data' => json_encode(['title' => $type]),
        'created_at' => '2026-11-09 07:00:00',
    ];

    $this->cycleStart = CarbonImmutable::parse('2026-11-09 09:00:00', 'Europe/Berlin');

    $this->installDigest = fn (array $defaults, array $preferences, ?string $lastSentAt = null, int $claimedRows = 1): StaticQueryConnection => StaticQueryConnection::install(
        fn (string $sql): array => match (true) {
            str_contains($sql, 'from "tenants"') => [['id' => $this->tenantId, 'name' => 'Digest', 'slug' => 'digest']],
            str_contains($sql, 'from "notification_digest_state"') => [['id' => ModelStub::ulid('digest-runner-state'), 'tenant_id' => $this->tenantId, 'user_id' => $this->userId, 'frequency' => 'daily', 'hour' => 9, 'last_digest_sent_at' => $lastSentAt]],
            str_contains($sql, 'from "notification_inbox"') => [($this->inboxEntry)('record.assigned'), ($this->inboxEntry)('reminder.due'), ($this->inboxEntry)('rule.triggered')],
            str_contains($sql, 'from "notification_type_defaults"') => $defaults,
            str_contains($sql, 'from "user_notification_preferences"') => $preferences,
            str_contains($sql, 'from "users"') => [['id' => $this->userId, 'tenant_id' => $this->tenantId, 'email' => 'digest@acme.test']],
            default => [],
        },
        static fn (string $sql): int => str_contains($sql, 'update "notification_digest_state"') ? $claimedRows : 1,
    );

    $this->everyTypeDigested = [
        ['type' => 'record.assigned', 'channel' => 'email', 'enabled' => true, 'delivery_mode' => 'digest'],
        ['type' => 'reminder.due', 'channel' => 'email', 'enabled' => true, 'delivery_mode' => 'digest'],
        ['type' => 'rule.triggered', 'channel' => 'email', 'enabled' => true, 'delivery_mode' => 'digest'],
    ];

    $this->digestedTypes = function (array $defaults, array $preferences): ?array {
        Mail::fake();
        ($this->installDigest)($defaults, $preferences);

        try {
            (new DigestRunner)->run($this->tenantId, $this->userId, $this->cycleStart);
        } finally {
            StaticQueryConnection::uninstall();
        }

        $sent = Mail::sent(NotificationDigestMail::class)->first();

        return $sent === null ? null : collect($sent->entries)->pluck('type')->all();
    };
});

afterEach(function (): void {
    Carbon::setTestNow();
    AccessContext::forgetTenant();
});

it('leaves a type out of the digest once its mail channel is switched off, however it is delivered', function (): void {
    $types = ($this->digestedTypes)(
        [
            ['type' => 'record.assigned', 'channel' => 'email', 'enabled' => true, 'delivery_mode' => 'digest'],
            ['type' => 'reminder.due', 'channel' => 'email', 'enabled' => false, 'delivery_mode' => 'digest'],
            ['type' => 'rule.triggered', 'channel' => 'email', 'enabled' => true, 'delivery_mode' => 'digest'],
        ],
        [
            ['user_id' => $this->userId, 'type' => 'rule.triggered', 'channel' => 'email', 'enabled' => false, 'delivery_mode' => 'digest'],
        ],
    );

    expect($types)->toBe(['record.assigned']);
});

it('lets the own preference switch a type the tenant turned off back into the digest', function (): void {
    $types = ($this->digestedTypes)(
        [['type' => 'reminder.due', 'channel' => 'email', 'enabled' => false, 'delivery_mode' => 'digest']],
        [['user_id' => $this->userId, 'type' => 'reminder.due', 'channel' => 'email', 'enabled' => true, 'delivery_mode' => 'digest']],
    );

    expect($types)->toBe(['reminder.due']);
});

it('sends no digest at all when every digested type is switched off', function (): void {
    $types = ($this->digestedTypes)(
        [
            ['type' => 'record.assigned', 'channel' => 'email', 'enabled' => false, 'delivery_mode' => 'digest'],
            ['type' => 'reminder.due', 'channel' => 'email', 'enabled' => false, 'delivery_mode' => 'digest'],
        ],
        [],
    );

    expect($types)->toBeNull();
});

it('claims the digest cycle in one conditional write that leaves the switch-on moment alone, so a second, overlapping scan sends nothing', function (): void {
    Mail::fake();
    Carbon::setTestNow('2026-11-09 08:30:00');
    $connection = ($this->installDigest)($this->everyTypeDigested, [], '2026-11-08 08:05:00', 0);

    try {
        (new DigestRunner)->run($this->tenantId, $this->userId, $this->cycleStart);
    } finally {
        StaticQueryConnection::uninstall();
    }

    $claim = $connection->writtenStatements[0] ?? ['sql' => '', 'bindings' => []];
    $claim['bindings'] = $connection->prepareBindings($claim['bindings']);

    expect($claim['sql'])->toBe('update "notification_digest_state" set "last_digest_sent_at" = ? where "notification_digest_state"."id" = ? and ("last_digest_sent_at" is null or "last_digest_sent_at" < ?) and "notification_digest_state"."tenant_id" = ?')
        ->and($claim['bindings'][0])->toBe('2026-11-09 08:30:00')
        ->and($claim['bindings'][2])->toBe('2026-11-09 08:00:00')
        ->and($connection->writtenStatements)->toHaveCount(1);

    Mail::assertNothingSent();
});

it('collects only the entries after the previous digest and up to the moment it claimed the cycle', function (): void {
    Mail::fake();
    Carbon::setTestNow('2026-11-09 08:30:00');
    $connection = ($this->installDigest)($this->everyTypeDigested, [], '2026-11-08 08:05:00');

    try {
        (new DigestRunner)->run($this->tenantId, $this->userId, $this->cycleStart);
    } finally {
        StaticQueryConnection::uninstall();
    }

    $inboxQuery = collect($connection->queries)->first(static fn (array $query): bool => str_contains($query['sql'], 'from "notification_inbox"'));

    expect($inboxQuery['sql'] ?? '')->toContain('"created_at" > ? and "created_at" <= ?')
        ->and($connection->prepareBindings($inboxQuery['bindings'] ?? []))->toContain('2026-11-08 08:05:00', '2026-11-09 08:30:00');

    Mail::assertSent(NotificationDigestMail::class, 1);
});

it('hands the cycle back without moving the switch-on moment when the digest mail fails, so the next scan sends it again, and lets the failure surface', function (): void {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));
    Carbon::setTestNow('2026-11-09 08:30:00');
    $connection = ($this->installDigest)($this->everyTypeDigested, [], '2026-11-08 08:05:00');

    try {
        expect(fn () => (new DigestRunner)->run($this->tenantId, $this->userId, $this->cycleStart))->toThrow(RuntimeException::class, 'smtp down');
    } finally {
        StaticQueryConnection::uninstall();
    }

    $release = $connection->writtenStatements[1] ?? ['sql' => '', 'bindings' => []];
    $release['bindings'] = $connection->prepareBindings($release['bindings']);

    expect($release['sql'])->toBe('update "notification_digest_state" set "last_digest_sent_at" = ? where "notification_digest_state"."id" = ? and "last_digest_sent_at" = ? and "notification_digest_state"."tenant_id" = ?')
        ->and($release['bindings'][0])->toBe('2026-11-08 08:05:00')
        ->and($release['bindings'][2])->toBe('2026-11-09 08:30:00');
});

it('reaches back no further than the moment the digest was switched on, and at most one digest period, for the very first digest', function (string $frequency, string $switchedOnAt, string $expectedLowerBound): void {
    Mail::fake();
    Carbon::setTestNow('2026-11-09 08:30:00');
    $connection = StaticQueryConnection::install(
        fn (string $sql): array => match (true) {
            str_contains($sql, 'from "tenants"') => [['id' => $this->tenantId, 'name' => 'Digest', 'slug' => 'digest']],
            str_contains($sql, 'from "notification_digest_state"') => [['id' => ModelStub::ulid('digest-runner-state'), 'tenant_id' => $this->tenantId, 'user_id' => $this->userId, 'frequency' => $frequency, 'hour' => 9, 'last_digest_sent_at' => null, 'updated_at' => $switchedOnAt]],
            default => [],
        },
        static fn (): int => 1,
    );

    try {
        (new DigestRunner)->run($this->tenantId, $this->userId, $this->cycleStart);
    } finally {
        StaticQueryConnection::uninstall();
    }

    $inboxQuery = collect($connection->queries)->first(static fn (array $query): bool => str_contains($query['sql'], 'from "notification_inbox"'));

    expect($inboxQuery['sql'] ?? '')->toContain('"created_at" > ? and "created_at" <= ?')
        ->and($connection->prepareBindings($inboxQuery['bindings'] ?? []))->toContain($expectedLowerBound, '2026-11-09 08:30:00');
})->with([
    'switched on this morning' => ['daily', '2026-11-09 06:00:00', '2026-11-09 06:00:00'],
    'daily digest switched on long ago' => ['daily', '2025-01-01 06:00:00', '2026-11-08 08:30:00'],
    'weekly digest switched on long ago' => ['weekly', '2025-01-01 06:00:00', '2026-11-02 08:30:00'],
]);
