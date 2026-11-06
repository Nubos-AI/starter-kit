<?php

declare(strict_types=1);

use App\Actions\Notifications\RegisterPushSubscriptionAction;
use App\Actions\Notifications\UnregisterPushSubscriptionAction;
use App\Actions\Reminders\CreateReminderTaskAction;
use App\Models\CustomRecord;
use App\Models\NotificationRule;
use App\Models\ObjectType;
use App\Models\User;
use App\Notifications\RuleNotification;
use App\Support\Notifications\RuleActionRunner;
use App\Support\Watchers\WatcherResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
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
    $this->tenant = AccessContext::tenant('rule-runner-tenant');

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

it('demands an endpoint before it stores a push subscription', function (): void {
    $user = AccessContext::user($this->tenant, [], 'rule-runner-recipient');
    $action = new RegisterPushSubscriptionAction;

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

    $attempt = QueryShape::attemptedBy(fn (): PushSubscription => (new RegisterPushSubscriptionAction)
        ->execute($user, ['endpoint' => 'https://push.example/abc']));

    expect($attempt?->targets('push_subscriptions'))->toBeTrue()
        ->and($attempt?->hasBinding('https://push.example/abc'))->toBeTrue();
});
