<?php

declare(strict_types=1);

use App\Actions\Notifications\RegisterPushSubscriptionAction;
use App\Actions\Notifications\UnregisterPushSubscriptionAction;
use App\Actions\Reminders\CreateReminderTaskAction;
use App\Models\CustomRecord;
use App\Models\NotificationRule;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Notifications\RuleActionRunner;
use App\Support\Watchers\WatcherResolver;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use NotificationChannels\WebPush\PushSubscription;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
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

it('asks the watcher resolver before it writes a single dispatch row', function (): void {
    $recipient = AccessContext::user($this->tenant, [], 'rule-runner-recipient');
    $resolver = ($this->resolverReturning)($recipient);

    $runner = new RuleActionRunner($resolver, app(CreateReminderTaskAction::class));

    expect(WriteAttempt::reachedTheDatabase(fn () => $runner->run($this->rule, $this->record, 'due')))->toBeTrue()
        ->and($resolver->calls)->toBe(1);
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

    expect(WriteAttempt::reachedTheDatabase(fn (): PushSubscription => (new RegisterPushSubscriptionAction)
        ->execute($user, ['endpoint' => 'https://push.example/abc'])))->toBeTrue();
});
