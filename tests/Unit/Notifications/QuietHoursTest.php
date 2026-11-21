<?php

declare(strict_types=1);

use App\Enums\Notifications\DeliveryMode;
use App\Enums\Notifications\NotificationChannel;
use App\Models\User;
use App\Notifications\Channels\InboxChannel;
use App\Support\Notifications\ChannelPreferenceResolver;
use Illuminate\Support\Carbon;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('quiet-hours-tenant');
    $this->resolver = app(ChannelPreferenceResolver::class);

    $this->userWith = fn (?string $start, ?string $end, ?string $timezone = null): User => AccessContext::user($this->tenant, [
        'quiet_hours_start' => $start,
        'quiet_hours_end' => $end,
        'timezone' => $timezone,
    ], 'quiet-hours-user');

    $this->atUtc = static function (string $instant, Closure $call): mixed {
        Carbon::setTestNow(Carbon::parse($instant, 'UTC'));

        try {
            return $call();
        } finally {
            Carbon::setTestNow();
        }
    };
});

afterEach(function (): void {
    Carbon::setTestNow();
    AccessContext::forgetTenant();
});

it('is quiet inside a window that stays inside one day', function (): void {
    $user = ($this->userWith)('09:00', '17:00', 'UTC');

    expect(($this->atUtc)('2026-09-23 09:00:00', fn (): bool => $this->resolver->inQuietHours($user)))->toBeTrue()
        ->and(($this->atUtc)('2026-09-23 16:59:00', fn (): bool => $this->resolver->inQuietHours($user)))->toBeTrue();
});

it('is loud again on the closing edge of the window', function (): void {
    $user = ($this->userWith)('09:00', '17:00', 'UTC');

    expect(($this->atUtc)('2026-09-23 17:00:00', fn (): bool => $this->resolver->inQuietHours($user)))->toBeFalse()
        ->and(($this->atUtc)('2026-09-23 08:59:00', fn (): bool => $this->resolver->inQuietHours($user)))->toBeFalse();
});

it('keeps a window that wraps around midnight quiet on both sides of it', function (): void {
    $user = ($this->userWith)('22:00', '06:00', 'UTC');

    expect(($this->atUtc)('2026-09-23 23:30:00', fn (): bool => $this->resolver->inQuietHours($user)))->toBeTrue()
        ->and(($this->atUtc)('2026-09-23 02:00:00', fn (): bool => $this->resolver->inQuietHours($user)))->toBeTrue()
        ->and(($this->atUtc)('2026-09-23 06:00:00', fn (): bool => $this->resolver->inQuietHours($user)))->toBeFalse()
        ->and(($this->atUtc)('2026-09-23 12:00:00', fn (): bool => $this->resolver->inQuietHours($user)))->toBeFalse();
});

it('reads the clock in the timezone of the user, not in the one of the server', function (): void {
    $berlin = ($this->userWith)('22:00', '06:00', 'Europe/Berlin');
    $utc = ($this->userWith)('22:00', '06:00', 'UTC');

    expect(($this->atUtc)('2026-09-23 21:00:00', fn (): bool => $this->resolver->inQuietHours($berlin)))->toBeTrue()
        ->and(($this->atUtc)('2026-09-23 21:00:00', fn (): bool => $this->resolver->inQuietHours($utc)))->toBeFalse();
});

it('falls back to the tenant setting while the user has not named a complete window', function (?string $start, ?string $end): void {
    $user = ($this->userWith)($start, $end, 'UTC');

    $attempt = QueryShape::attemptedBy(fn (): bool => $this->resolver->inQuietHours($user));

    expect($attempt)->not->toBeNull()
        ->and($attempt?->targets('tenant_settings'))->toBeTrue()
        ->and($attempt?->hasBinding((string) $this->tenant->getKey()))->toBeTrue();
})->with([
    'no quiet hours at all' => [null, null],
    'only one edge of the window' => ['22:00', null],
]);

it('reads the preference of one user for one type and one channel', function (): void {
    $user = ($this->userWith)('22:00', '06:00', 'UTC');

    $attempt = QueryShape::attemptedBy(fn (): array => $this->resolver->resolve($user, 'record.assigned', NotificationChannel::Email));

    expect($attempt?->targets('user_notification_preferences'))->toBeTrue()
        ->and($attempt?->hasBinding((string) $user->getKey()))->toBeTrue()
        ->and($attempt?->hasBinding('record.assigned'))->toBeTrue()
        ->and($attempt?->hasBinding(NotificationChannel::Email->value))->toBeTrue();
});

it('lets the own preference beat the tenant default and the default beat the built in delivery', function (?array $own, ?array $tenantDefault, array $expected): void {
    StaticQueryConnection::install(static fn (string $sql): array => match (true) {
        str_contains($sql, 'from "user_notification_preferences"') => $own === null ? [] : [$own],
        str_contains($sql, 'from "notification_type_defaults"') => $tenantDefault === null ? [] : [$tenantDefault],
        default => [],
    });

    try {
        $resolved = $this->resolver->resolve(($this->userWith)(null, null), 'record.assigned', NotificationChannel::Email);
    } finally {
        StaticQueryConnection::uninstall();
    }

    expect($resolved)->toBe($expected);
})->with([
    'the own preference' => [['enabled' => false, 'delivery_mode' => 'digest'], ['enabled' => true, 'delivery_mode' => 'immediate'], ['enabled' => false, 'delivery_mode' => DeliveryMode::Digest]],
    'the tenant default' => [null, ['enabled' => false, 'delivery_mode' => 'immediate'], ['enabled' => false, 'delivery_mode' => DeliveryMode::Immediate]],
    'the built in delivery' => [null, null, ['enabled' => true, 'delivery_mode' => DeliveryMode::Immediate]],
]);

it('holds mail and push back during the quiet hours and keeps the inbox', function (string $instant, array $channels): void {
    $user = ($this->userWith)('22:00', '06:00', 'UTC');
    StaticQueryConnection::install(static fn (string $sql): array => str_contains($sql, 'exists') ? [['exists' => true]] : []);

    try {
        $resolved = ($this->atUtc)($instant, fn (): array => $this->resolver->channelsFor($user, 'record.assigned'));
    } finally {
        StaticQueryConnection::uninstall();
    }

    expect($resolved)->toBe($channels);
})->with([
    'inside the quiet hours' => ['2026-05-04 23:30:00', [InboxChannel::class]],
    'outside the quiet hours' => ['2026-05-04 12:00:00', [InboxChannel::class, 'mail', WebPushChannel::class]],
]);
