<?php

declare(strict_types=1);

use App\Console\Commands\SendNotificationDigests;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Notifications\DigestRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenantId = ModelStub::ulid('digest-command-tenant');
    $this->userId = ModelStub::ulid('digest-command-user');

    $this->runner = new class extends DigestRunner
    {
        /**
         * @var list<string>
         */
        public array $ranFor = [];

        /**
         * @var list<string>
         */
        public array $cycleStarts = [];

        public ?string $failingUserId = null;

        public function run(string $tenantId, string $userId, CarbonImmutable $cycleStart): void
        {
            if ($userId === $this->failingUserId) {
                throw new RuntimeException('smtp down');
            }

            $this->ranFor[] = $userId;
            $this->cycleStarts[] = $cycleStart->toIso8601String();
        }
    };

    $this->lockRegistry = new class extends MaintenanceLockRegistry
    {
        public function __construct() {}

        public function lockedTenantIds(): array
        {
            return [];
        }
    };

    $this->isSentAt = function (string $now, string $frequency, ?string $lastSentAt, string $configuredAt = '2026-10-01 12:00:00', string $timezone = 'UTC', int $hour = 9): bool {
        Carbon::setTestNow(Carbon::parse($now, 'UTC'));
        StaticQueryConnection::install(fn (string $sql): array => match (true) {
            str_contains($sql, 'from "notification_digest_state"') => [[
                'id' => ModelStub::ulid('digest-command-state'),
                'tenant_id' => $this->tenantId,
                'user_id' => $this->userId,
                'frequency' => $frequency,
                'hour' => $hour,
                'last_digest_sent_at' => $lastSentAt,
                'created_at' => $configuredAt,
                'updated_at' => $configuredAt,
            ]],
            str_contains($sql, 'from "users"') => [['id' => $this->userId, 'tenant_id' => $this->tenantId, 'email' => 'digest@acme.test', 'timezone' => $timezone]],
            default => [],
        });

        try {
            (new SendNotificationDigests)->handle($this->runner, $this->lockRegistry);
        } finally {
            StaticQueryConnection::uninstall();
            Carbon::setTestNow();
        }

        $isSent = $this->runner->ranFor !== [];
        $this->runner->ranFor = [];

        return $isSent;
    };
});

afterEach(function (): void {
    Carbon::setTestNow();
    AccessContext::forgetTenant();
});

it('still sends the daily digest when the scan reaches the user only after the first quarter of the digest hour', function (string $now): void {
    expect(($this->isSentAt)($now, 'daily', '2026-11-08 09:05:00'))->toBeTrue();
})->with([
    'twenty minutes late' => ['2026-11-09 09:20:00'],
    'hours late' => ['2026-11-09 15:00:00'],
    'just before midnight' => ['2026-11-09 23:55:00'],
]);

it('sends the daily digest once per day and not before the digest hour', function (string $now, ?string $lastSentAt, bool $isSent): void {
    expect(($this->isSentAt)($now, 'daily', $lastSentAt))->toBe($isSent);
})->with([
    'already sent today' => ['2026-11-09 15:00:00', '2026-11-09 09:05:00', false],
    'before the hour, sent yesterday' => ['2026-11-09 08:59:00', '2026-11-08 09:05:00', false],
    'on the hour, sent yesterday' => ['2026-11-09 09:00:00', '2026-11-08 09:05:00', true],
    'yesterday missed entirely' => ['2026-11-09 03:00:00', '2026-11-07 09:05:00', true],
    'never sent, configured long ago' => ['2026-11-09 09:30:00', null, true],
]);

it('waits for the first digest hour after the digest was switched on', function (): void {
    expect(($this->isSentAt)('2026-11-09 10:30:00', 'daily', null, '2026-11-09 10:00:00'))->toBeFalse()
        ->and(($this->isSentAt)('2026-11-10 09:01:00', 'daily', null, '2026-11-09 10:00:00'))->toBeTrue();
});

it('reads the digest hour on the clock of the user', function (): void {
    expect(($this->isSentAt)('2026-11-09 08:30:00', 'daily', '2026-11-08 08:05:00', timezone: 'Europe/Berlin'))->toBeTrue()
        ->and(($this->isSentAt)('2026-11-09 07:30:00', 'daily', '2026-11-08 08:05:00', timezone: 'Europe/Berlin'))->toBeFalse();
});

it('sends the weekly digest from monday on once per week, catching up a missed monday', function (string $now, string $lastSentAt, bool $isSent): void {
    expect(($this->isSentAt)($now, 'weekly', $lastSentAt))->toBe($isSent);
})->with([
    'monday after the hour' => ['2026-11-09 11:00:00', '2026-11-02 09:05:00', true],
    'monday before the hour' => ['2026-11-09 08:00:00', '2026-11-02 09:05:00', false],
    'tuesday after a missed monday' => ['2026-11-10 10:00:00', '2026-11-02 09:05:00', true],
    'tuesday after a sent monday' => ['2026-11-10 10:00:00', '2026-11-09 09:05:00', false],
    'sunday before the next monday' => ['2026-11-15 22:00:00', '2026-11-09 09:05:00', false],
]);

it('hands the runner the start of the cycle it is due for, on the clock of the user', function (): void {
    ($this->isSentAt)('2026-11-09 15:00:00', 'daily', '2026-11-08 08:05:00', timezone: 'Europe/Berlin');
    $daily = $this->runner->cycleStarts;
    $this->runner->cycleStarts = [];
    ($this->isSentAt)('2026-11-11 10:00:00', 'weekly', '2026-11-02 09:05:00');

    expect($daily)->toBe(['2026-11-09T09:00:00+01:00'])
        ->and($this->runner->cycleStarts)->toBe(['2026-11-09T09:00:00+00:00']);
});

it('logs a user whose digest fails with the identifiers and still runs the digests of the users after it', function (): void {
    Log::spy();
    Carbon::setTestNow(Carbon::parse('2026-11-09 10:00:00', 'UTC'));
    $failingUserId = ModelStub::ulid('digest-command-failing-user');
    $this->runner->failingUserId = $failingUserId;
    $state = fn (string $userId): array => [
        'id' => ModelStub::ulid("digest-command-state-{$userId}"),
        'tenant_id' => $this->tenantId,
        'user_id' => $userId,
        'frequency' => 'daily',
        'hour' => 9,
        'last_digest_sent_at' => '2026-11-08 09:05:00',
        'created_at' => '2026-10-01 12:00:00',
        'updated_at' => '2026-10-01 12:00:00',
    ];
    StaticQueryConnection::install(fn (string $sql): array => match (true) {
        str_contains($sql, 'from "notification_digest_state"') => [$state($failingUserId), $state($this->userId)],
        str_contains($sql, 'from "users"') => [
            ['id' => $failingUserId, 'tenant_id' => $this->tenantId, 'email' => 'failing@acme.test', 'timezone' => 'UTC'],
            ['id' => $this->userId, 'tenant_id' => $this->tenantId, 'email' => 'digest@acme.test', 'timezone' => 'UTC'],
        ],
        default => [],
    });

    try {
        $exitCode = (new SendNotificationDigests)->handle($this->runner, $this->lockRegistry);
    } finally {
        StaticQueryConnection::uninstall();
    }

    expect($this->runner->ranFor)->toBe([$this->userId])
        ->and($exitCode)->toBe(SendNotificationDigests::FAILURE);

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $context['user_id'] === $failingUserId
        && $context['tenant_id'] === $this->tenantId
        && $context['exception'] instanceof RuntimeException);
});

it('sends one digest on the day the clocks go back, although the digest hour occurs twice', function (string $now, string $lastSentAt, bool $isSent): void {
    expect(($this->isSentAt)($now, 'daily', $lastSentAt, timezone: 'Europe/Berlin', hour: 2))->toBe($isSent);
})->with([
    'first two o clock' => ['2026-10-25 00:30:00', '2026-10-24 00:05:00', true],
    'repeated two o clock' => ['2026-10-25 01:30:00', '2026-10-25 00:30:00', false],
    'the day after' => ['2026-10-26 01:30:00', '2026-10-25 00:30:00', true],
]);

it('sends the digest on the day the clocks go forward once the skipped digest hour has passed', function (string $now, bool $isSent): void {
    expect(($this->isSentAt)($now, 'daily', '2026-03-28 01:05:00', timezone: 'Europe/Berlin', hour: 2))->toBe($isSent);
})->with([
    'before the gap' => ['2026-03-29 00:30:00', false],
    'after the gap' => ['2026-03-29 01:30:00', true],
]);
