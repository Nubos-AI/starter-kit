<?php

declare(strict_types=1);

use App\Actions\Notifications\RegisterPushSubscriptionAction;
use App\Actions\Notifications\UnregisterPushSubscriptionAction;
use App\Actions\Reminders\CreateReminderTaskAction;
use App\Exceptions\Notifications\RuleDispatchFailedException;
use App\Models\CustomRecord;
use App\Models\NotificationRule;
use App\Models\ObjectType;
use App\Models\User;
use App\Notifications\Channels\InboxChannel;
use App\Notifications\RuleNotification;
use App\Support\Notifications\ChannelPreferenceResolver;
use App\Support\Notifications\RuleActionRunner;
use App\Support\Watchers\WatcherResolver;
use App\Support\Webhooks\SsrfGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Testing\Fakes\NotificationFake;
use Illuminate\Validation\ValidationException;
use NotificationChannels\WebPush\PushSubscription;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\Support\WriteAttempt;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    /** @var callable(Closure):?array{sql: string, bindings: array<int, mixed>} */
    $this->pushInsertOf = static function (Closure $register): ?array {
        $connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);

        try {
            $register();
        } finally {
            StaticQueryConnection::uninstall();
        }

        return collect($connection->writtenStatements)
            ->first(static fn (array $statement): bool => str_starts_with($statement['sql'], 'insert into "push_subscriptions"'));
    };

    $this->tenant = AccessContext::tenant('rule-runner-tenant');

    app()->instance(ChannelPreferenceResolver::class, new class extends ChannelPreferenceResolver
    {
        public function channelsFor(User $user, string $type): array
        {
            return [InboxChannel::class];
        }
    });

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('rule-runner-type'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('rule-runner-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
    ], ['objectType' => $this->objectType]);

    $this->rule = ModelStub::make(NotificationRule::class, [
        'id' => ModelStub::ulid('rule-runner-rule'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
        'name' => 'Stale deal',
        'action' => ['notify' => true, 'create_reminder' => null],
    ]);

    $this->registerPushResolvingTo = static fn (array $ips): RegisterPushSubscriptionAction => new RegisterPushSubscriptionAction(
        new SsrfGuard(static fn (string $host): array => $ips),
    );

    $this->resolverReturning = static function (User ...$recipients): WatcherResolver {
        return new class(new Collection(array_values($recipients))) extends WatcherResolver
        {
            public int $calls = 0;

            /**
             * @param  Collection<int, User>  $recipients
             */
            public function __construct(private readonly Collection $recipients) {}

            public function recipientsFor(CustomRecord $record): Collection
            {
                $this->calls++;

                return $this->recipients;
            }
        };
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('writes no dispatch row at all when the watcher resolver hands back nobody', function (): void {
    $resolver = ($this->resolverReturning)();

    $runner = new RuleActionRunner($resolver, app(CreateReminderTaskAction::class));

    expect(WriteAttempt::reachedTheDatabase(fn () => $runner->run($this->rule, $this->record, 'due')))->toBeFalse()
        ->and($resolver->calls)->toBe(1);
});

it('notifies a watcher once and never again for a stage it was already told about', function (bool $wasDispatched): void {
    Notification::fake();
    $recipient = AccessContext::user($this->tenant, [], 'rule-runner-recipient');
    $existing = [
        'id' => ModelStub::ulid('rule-runner-dispatch'),
        'rule_id' => $this->rule->getKey(),
        'record_id' => $this->record->getKey(),
        'user_id' => $recipient->getKey(),
        'stage' => 'due',
        'dispatched_at' => '2026-11-09 08:00:00',
    ];
    $connection = StaticQueryConnection::install(
        static fn (string $sql): array => str_contains($sql, 'from "notification_rule_dispatches"') && $wasDispatched ? [$existing] : [],
        static fn (): int => 1,
    );

    try {
        (new RuleActionRunner(($this->resolverReturning)($recipient), app(CreateReminderTaskAction::class)))
            ->run($this->rule, $this->record, 'due');
    } finally {
        StaticQueryConnection::uninstall();
    }

    $inserts = array_values(array_filter(
        $connection->writtenStatements,
        static fn (array $statement): bool => str_starts_with($statement['sql'], 'insert into "notification_rule_dispatches"'),
    ));

    if ($wasDispatched) {
        expect($inserts)->toBe([]);
        Notification::assertNothingSent();

        return;
    }

    expect($inserts)->toHaveCount(1)
        ->and($inserts[0]['bindings'])->toContain($this->rule->getKey(), $this->record->getKey(), $recipient->getKey(), 'due');
    Notification::assertSentToTimes($recipient, RuleNotification::class, 1);
})->with([
    'a first dispatch for the stage' => [false],
    'a dispatch row already exists' => [true],
]);

it('creates the follow-up reminder of a rule once per record and stage however many watchers it tells', function (): void {
    Notification::fake();
    $creator = AccessContext::user($this->tenant, [], 'rule-runner-creator');
    $this->rule->setAttribute('action', ['notify' => true, 'create_reminder' => ['subject' => 'Nachfassen']]);
    $this->rule->setRelation('creator', $creator);
    $recipients = [
        AccessContext::user($this->tenant, [], 'rule-runner-first'),
        AccessContext::user($this->tenant, [], 'rule-runner-second'),
        AccessContext::user($this->tenant, [], 'rule-runner-third'),
    ];
    $claimed = [];
    StaticQueryConnection::install(
        static function (string $sql, array $bindings) use (&$claimed): array {
            if (!str_contains($sql, 'from "notification_rule_dispatches"')) {
                return [];
            }

            foreach ($claimed as $claim) {
                if (array_diff($claim, $bindings) === []) {
                    return [['id' => ModelStub::ulid('existing-dispatch')]];
                }
            }

            return [];
        },
        static function (string $sql, array $bindings) use (&$claimed): int {
            if (str_starts_with($sql, 'insert into "notification_rule_dispatches"')) {
                $claimed[] = array_slice($bindings, 0, 4);
            }

            return 1;
        },
    );
    $reminders = Mockery::mock(CreateReminderTaskAction::class);
    $reminders->shouldReceive('execute')->once();

    try {
        (new RuleActionRunner(($this->resolverReturning)(...$recipients), $reminders))->run($this->rule, $this->record, 'due');
    } finally {
        StaticQueryConnection::uninstall();
    }

    foreach ($recipients as $recipient) {
        Notification::assertSentToTimes($recipient, RuleNotification::class, 1);
    }
});

it('hands the claim of a recipient whose notification failed back instead of deleting it, so the retry tells that recipient and keeps what was delivered', function (): void {
    $recipient = AccessContext::user($this->tenant, [], 'rule-runner-failing');
    Notification::swap(new class extends NotificationFake
    {
        public function sendNow($notifiables, $notification, ?array $channels = null): void
        {
            throw new RuntimeException('mail transport down');
        }
    });
    $connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);

    try {
        expect(fn () => (new RuleActionRunner(($this->resolverReturning)($recipient), app(CreateReminderTaskAction::class)))
            ->dispatchTo($this->rule, $this->record, $recipient, 'due'))->toThrow(RuntimeException::class, 'mail transport down');
    } finally {
        StaticQueryConnection::uninstall();
    }

    $writes = $connection->writtenSqlOf('notification_rule_dispatches');

    expect($writes)->toHaveCount(2)
        ->and($writes[0])->toStartWith('insert into "notification_rule_dispatches"')
        ->and($writes[1])->toBe('update "notification_rule_dispatches" set "dispatched_at" = ? where "notification_rule_dispatches"."id" = ? and "notification_rule_dispatches"."tenant_id" = ?');
});

it('delivers every channel of a rule notification once, so a mail failure after the inbox entry retries only the mail', function (?array $existing, int $reclaimedRows, ?string $failingChannel, array $expectedSends, array $expectedChannelWrites): void {
    $recipient = AccessContext::user($this->tenant, [], 'rule-runner-channels');
    app()->instance(ChannelPreferenceResolver::class, new class extends ChannelPreferenceResolver
    {
        public function channelsFor(User $user, string $type): array
        {
            return [InboxChannel::class, 'mail'];
        }
    });
    $notifications = new class($failingChannel) extends NotificationFake
    {
        /**
         * @var list<list<string>>
         */
        public array $sentChannels = [];

        public function __construct(private readonly ?string $failingChannel) {}

        public function sendNow($notifiables, $notification, ?array $channels = null): void
        {
            if ($channels === [$this->failingChannel]) {
                throw new RuntimeException('mail transport down');
            }

            $this->sentChannels[] = $channels ?? [];

            parent::sendNow($notifiables, $notification, $channels);
        }
    };
    Notification::swap($notifications);
    $row = $existing === null ? null : [
        'id' => ModelStub::ulid('rule-runner-channel-dispatch'),
        'tenant_id' => $this->tenant->getKey(),
        'rule_id' => $this->rule->getKey(),
        'record_id' => $this->record->getKey(),
        'user_id' => $recipient->getKey(),
        'stage' => 'due',
        ...$existing,
    ];
    $connection = StaticQueryConnection::install(
        static fn (string $sql): array => str_contains($sql, 'from "notification_rule_dispatches"') && $row !== null ? [$row] : [],
        static fn (string $sql): int => str_contains($sql, '"dispatched_at" is null') ? $reclaimedRows : 1,
    );

    try {
        (new RuleActionRunner(($this->resolverReturning)($recipient), app(CreateReminderTaskAction::class)))
            ->dispatchTo($this->rule, $this->record, $recipient, 'due');
    } catch (RuntimeException) {
    } finally {
        StaticQueryConnection::uninstall();
    }

    $channelWrites = array_values(array_map(
        static fn (array $write): mixed => $write['bindings'][0],
        array_filter($connection->writtenStatements, static fn (array $write): bool => str_contains($write['sql'], 'set "notified_channels" = ?')),
    ));

    expect($notifications->sentChannels)->toBe($expectedSends)
        ->and($channelWrites)->toBe($expectedChannelWrites);
})->with([
    'a first dispatch that delivers every channel' => [null, 1, null, [[InboxChannel::class], ['mail']], [json_encode([InboxChannel::class]), null]],
    'the mail fails after the inbox entry' => [null, 1, 'mail', [[InboxChannel::class]], [json_encode([InboxChannel::class])]],
    'the retry after the inbox entry was delivered' => [['dispatched_at' => null, 'notified_channels' => json_encode([InboxChannel::class])], 1, null, [['mail']], [null]],
    'a released dispatch another run took back first' => [['dispatched_at' => null, 'notified_channels' => json_encode([InboxChannel::class])], 0, null, [], []],
    'a dispatch that is claimed or done' => [['dispatched_at' => '2026-11-09 08:00:00', 'notified_channels' => null], 1, null, [], []],
]);

it('takes a released dispatch back in one conditional write before it delivers the missing channels', function (): void {
    Notification::fake();
    $recipient = AccessContext::user($this->tenant, [], 'rule-runner-reclaim');
    $dispatchId = ModelStub::ulid('rule-runner-reclaim-dispatch');
    $connection = StaticQueryConnection::install(
        fn (string $sql): array => str_contains($sql, 'from "notification_rule_dispatches"') ? [[
            'id' => $dispatchId,
            'tenant_id' => $this->tenant->getKey(),
            'rule_id' => $this->rule->getKey(),
            'record_id' => $this->record->getKey(),
            'user_id' => $recipient->getKey(),
            'stage' => 'due',
            'dispatched_at' => null,
            'notified_channels' => json_encode([]),
        ]] : [],
        static fn (): int => 1,
    );

    try {
        (new RuleActionRunner(($this->resolverReturning)($recipient), app(CreateReminderTaskAction::class)))
            ->dispatchTo($this->rule, $this->record, $recipient, 'due');
    } finally {
        StaticQueryConnection::uninstall();
    }

    $reclaim = $connection->writtenStatements[0] ?? ['sql' => '', 'bindings' => []];

    expect($reclaim['sql'])->toBe('update "notification_rule_dispatches" set "dispatched_at" = ? where "notification_rule_dispatches"."id" = ? and "dispatched_at" is null and "notification_rule_dispatches"."tenant_id" = ?')
        ->and($reclaim['bindings'][1])->toBe($dispatchId);
    Notification::assertSentToTimes($recipient, RuleNotification::class, 1);
});

it('creates the follow-up reminder on the retry although the watcher was already told, so a reminder that failed once is not lost', function (): void {
    Notification::fake();
    $recipient = AccessContext::user($this->tenant, [], 'rule-runner-told-recipient');
    $this->rule->setAttribute('action', ['notify' => true, 'create_reminder' => ['subject' => 'Nachfassen']]);
    $this->rule->setRelation('creator', AccessContext::user($this->tenant, [], 'rule-runner-told-creator'));
    StaticQueryConnection::install(
        fn (string $sql, array $bindings): array => str_contains($sql, 'from "notification_rule_dispatches"') && in_array('due', $bindings, true) ? [[
            'id' => ModelStub::ulid('rule-runner-told-dispatch'),
            'tenant_id' => $this->tenant->getKey(),
            'rule_id' => $this->rule->getKey(),
            'record_id' => $this->record->getKey(),
            'user_id' => $recipient->getKey(),
            'stage' => 'due',
            'dispatched_at' => '2026-11-09 08:00:00',
        ]] : [],
        static fn (): int => 1,
    );
    $reminders = Mockery::mock(CreateReminderTaskAction::class);
    $reminders->shouldReceive('execute')->once();

    try {
        (new RuleActionRunner(($this->resolverReturning)($recipient), $reminders))->dispatchTo($this->rule, $this->record, $recipient, 'due');
    } finally {
        StaticQueryConnection::uninstall();
    }

    Notification::assertNothingSent();
});

it('tells every other recipient when one of them fails, logs the failed one and then reports the failure so the run is retried', function (): void {
    $failing = AccessContext::user($this->tenant, [], 'rule-runner-broken');
    $first = AccessContext::user($this->tenant, [], 'rule-runner-before');
    $last = AccessContext::user($this->tenant, [], 'rule-runner-after');
    $notifications = new class((string) $failing->getKey()) extends NotificationFake
    {
        public function __construct(private readonly string $failingUserId) {}

        public function sendNow($notifiables, $notification, ?array $channels = null): void
        {
            if ($notifiables instanceof User && $notifiables->getKey() === $this->failingUserId) {
                throw new RuntimeException('mail transport down');
            }

            parent::sendNow($notifiables, $notification, $channels);
        }
    };
    Notification::swap($notifications);
    Log::spy();
    StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);

    try {
        expect(fn () => (new RuleActionRunner(($this->resolverReturning)($first, $failing, $last), app(CreateReminderTaskAction::class)))
            ->run($this->rule, $this->record, 'due'))->toThrow(RuleDispatchFailedException::class);
    } finally {
        StaticQueryConnection::uninstall();
    }

    $notifications->assertSentToTimes($first, RuleNotification::class, 1);
    $notifications->assertSentToTimes($last, RuleNotification::class, 1);
    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $context['rule_id'] === $this->rule->getKey()
        && $context['record_id'] === $this->record->getKey()
        && $context['user_id'] === $failing->getKey()
        && $context['stage'] === 'due'
        && $context['exception'] instanceof RuntimeException);
});

it('logs a follow-up reminder the creator may no longer create with the rule context and keeps the notification run going', function (): void {
    Notification::fake();
    Log::spy();
    $recipient = AccessContext::user($this->tenant, [], 'rule-runner-reminder-recipient');
    $creator = AccessContext::user($this->tenant, [], 'rule-runner-reminder-creator');
    $this->rule->setAttribute('action', ['notify' => true, 'create_reminder' => ['subject' => 'Nachfassen']]);
    $this->rule->setRelation('creator', $creator);
    StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);
    $reminders = Mockery::mock(CreateReminderTaskAction::class);
    $reminders->shouldReceive('execute')->once()->andThrow(ValidationException::withMessages(['record_id' => 'gone']));

    try {
        (new RuleActionRunner(($this->resolverReturning)($recipient), $reminders))->dispatchTo($this->rule, $this->record, $recipient, 'due');
    } finally {
        StaticQueryConnection::uninstall();
    }

    Notification::assertSentToTimes($recipient, RuleNotification::class, 1);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context['rule_id'] === $this->rule->getKey()
        && $context['record_id'] === $this->record->getKey()
        && $context['creator_id'] === $creator->getKey()
        && $context['errors'] === ['record_id']);
});

it('releases the follow-up reminder claim and lets an unexpected failure surface so the retry creates the reminder', function (): void {
    Notification::fake();
    $recipient = AccessContext::user($this->tenant, [], 'rule-runner-crash-recipient');
    $this->rule->setAttribute('action', ['notify' => true, 'create_reminder' => ['subject' => 'Nachfassen']]);
    $this->rule->setRelation('creator', AccessContext::user($this->tenant, [], 'rule-runner-crash-creator'));
    $connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);
    $reminders = Mockery::mock(CreateReminderTaskAction::class);
    $reminders->shouldReceive('execute')->once()->andThrow(new RuntimeException('database gone'));

    try {
        expect(fn () => (new RuleActionRunner(($this->resolverReturning)($recipient), $reminders))->dispatchTo($this->rule, $this->record, $recipient, 'due'))
            ->toThrow(RuntimeException::class, 'database gone');
    } finally {
        StaticQueryConnection::uninstall();
    }

    $writes = array_values(array_filter(
        $connection->writtenStatements,
        static fn (array $statement): bool => str_contains($statement['sql'], '"notification_rule_dispatches"'),
    ));

    expect($writes)->toHaveCount(3)
        ->and($writes[1]['bindings'])->toContain('due:reminder')
        ->and($writes[2]['sql'])->toStartWith('delete from "notification_rule_dispatches"')
        ->and(array_intersect($writes[2]['bindings'], $writes[1]['bindings']))->not->toBe([]);
});

it('demands an endpoint before it stores a push subscription', function (): void {
    $user = AccessContext::user($this->tenant, [], 'rule-runner-recipient');
    $action = ($this->registerPushResolvingTo)(['93.184.216.34']);

    expect(fn (): PushSubscription => $action->execute($user, []))->toThrow(ValidationException::class)
        ->and(fn (): PushSubscription => $action->execute($user, ['endpoint' => ['nested']]))->toThrow(ValidationException::class);
});

it('demands an endpoint before it removes a push subscription', function (): void {
    $user = AccessContext::user($this->tenant, [], 'rule-runner-recipient');
    $action = new UnregisterPushSubscriptionAction;

    expect(fn () => $action->execute($user, []))->toThrow(ValidationException::class);
});

it('accepts a push subscription that names only an endpoint', function (): void {
    $user = AccessContext::user($this->tenant, [], 'rule-runner-recipient');

    $insert = ($this->pushInsertOf)(fn (): PushSubscription => ($this->registerPushResolvingTo)(['93.184.216.34'])
        ->execute($user, ['endpoint' => 'https://push.example/abc']));

    expect($insert)->not->toBeNull()
        ->and($insert['bindings'])->toContain('https://push.example/abc');
});

it('refuses a push endpoint that is no https address at the endpoint field and stores nothing', function (string $endpoint): void {
    $user = AccessContext::user($this->tenant, [], 'rule-runner-recipient');
    $errors = [];

    $attempt = QueryShape::attemptedBy(function () use ($user, $endpoint, &$errors): void {
        try {
            ($this->registerPushResolvingTo)(['93.184.216.34'])->execute($user, ['endpoint' => $endpoint]);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
        }
    });

    expect(array_keys($errors))->toBe(['endpoint'])
        ->and($attempt)->toBeNull();
})->with([
    'plain http' => ['http://push.example/abc'],
    'no address at all' => ['not an address'],
    'another scheme' => ['ftp://push.example/abc'],
]);

it('refuses a push endpoint whose host resolves into the internal network and tells the user in German', function (string $ip): void {
    $user = AccessContext::user($this->tenant, [], 'rule-runner-recipient');
    $errors = [];

    $attempt = QueryShape::attemptedBy(function () use ($user, $ip, &$errors): void {
        try {
            ($this->registerPushResolvingTo)([$ip])->execute($user, ['endpoint' => 'https://push.example/abc']);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
        }
    });

    expect($errors)->toBe(['endpoint' => [__('i18n.backend.actions.notifications.register_push_subscription_action.endpoint_blocked')]])
        ->and($errors['endpoint'][0])->toContain('Push-Endpunkt')
        ->and($attempt)->toBeNull();
})->with([
    'loopback' => ['127.0.0.1'],
    'cloud metadata' => ['169.254.169.254'],
    'private range' => ['10.0.0.5'],
]);

it('refuses a content encoding the push library does not know at its own field instead of failing', function (): void {
    $user = AccessContext::user($this->tenant, [], 'rule-runner-recipient');

    try {
        ($this->registerPushResolvingTo)(['93.184.216.34'])->execute($user, ['endpoint' => 'https://push.example/abc', 'content_encoding' => 'gzip']);
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['content_encoding']);

        return;
    }

    $this->fail('an unknown content encoding was accepted');
});

it('stores a content encoding the push library knows', function (): void {
    $user = AccessContext::user($this->tenant, [], 'rule-runner-recipient');

    $insert = ($this->pushInsertOf)(fn (): PushSubscription => ($this->registerPushResolvingTo)(['93.184.216.34'])
        ->execute($user, ['endpoint' => 'https://push.example/abc', 'content_encoding' => 'aes128gcm']));

    expect($insert)->not->toBeNull()
        ->and($insert['bindings'])->toContain('aes128gcm');
});

it('refuses browser keys the push library cannot encrypt with at their own field, so a later notification cannot fail on them', function (array $keys, string $field): void {
    $user = AccessContext::user($this->tenant, [], 'rule-runner-recipient');
    $errors = [];

    $attempt = QueryShape::attemptedBy(function () use ($user, $keys, &$errors): void {
        try {
            ($this->registerPushResolvingTo)(['93.184.216.34'])->execute($user, ['endpoint' => 'https://push.example/abc', ...$keys]);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
        }
    });

    expect(array_keys($errors))->toBe([$field])
        ->and($errors[$field][0])->toBe(__('i18n.backend.actions.notifications.register_push_subscription_action.invalid_key'))
        ->and($attempt)->toBeNull();
})->with([
    'garbage public key' => [['public_key' => 'garbage', 'auth_token' => rtrim(strtr(base64_encode(str_repeat("\x02", 16)), '+/', '-_'), '=')], 'public_key'],
    'compressed public key' => [['public_key' => rtrim(strtr(base64_encode("\x02".str_repeat("\x01", 32)), '+/', '-_'), '='), 'auth_token' => rtrim(strtr(base64_encode(str_repeat("\x02", 16)), '+/', '-_'), '=')], 'public_key'],
    'short auth token' => [['public_key' => rtrim(strtr(base64_encode("\x04".str_repeat("\x01", 64)), '+/', '-_'), '='), 'auth_token' => 'abc'], 'auth_token'],
]);

it('demands both browser keys once one of them is sent', function (): void {
    $user = AccessContext::user($this->tenant, [], 'rule-runner-recipient');

    try {
        ($this->registerPushResolvingTo)(['93.184.216.34'])->execute($user, ['endpoint' => 'https://push.example/abc', 'public_key' => rtrim(strtr(base64_encode("\x04".str_repeat("\x01", 64)), '+/', '-_'), '=')]);
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['auth_token']);

        return;
    }

    $this->fail('a public key without an auth token was accepted');
});

it('stores the browser keys a browser hands out', function (): void {
    $user = AccessContext::user($this->tenant, [], 'rule-runner-recipient');
    $publicKey = rtrim(strtr(base64_encode("\x04".str_repeat("\xfb", 64)), '+/', '-_'), '=');

    $insert = ($this->pushInsertOf)(fn (): PushSubscription => ($this->registerPushResolvingTo)(['93.184.216.34'])
        ->execute($user, ['endpoint' => 'https://push.example/abc', 'public_key' => $publicKey, 'auth_token' => rtrim(strtr(base64_encode(str_repeat("\xfe", 16)), '+/', '-_'), '=')]));

    expect($insert)->not->toBeNull()
        ->and($insert['bindings'])->toContain($publicKey);
});
