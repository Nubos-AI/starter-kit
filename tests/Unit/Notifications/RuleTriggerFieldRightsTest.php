<?php

declare(strict_types=1);

use App\Actions\Notifications\SaveNotificationRuleAction;
use App\Actions\Reminders\CreateReminderTaskAction;
use App\Enums\CustomFields\FieldType;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\NotificationRule;
use App\Models\ObjectType;
use App\Models\User;
use App\Notifications\Channels\InboxChannel;
use App\Notifications\RuleNotification;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\ObjectTypeFieldLookup;
use App\Support\Notifications\ChannelPreferenceResolver;
use App\Support\Notifications\RuleActionRunner;
use App\Support\Notifications\RuleMatcher;
use App\Support\Notifications\RuleSegmentGuard;
use App\Support\Notifications\RuleTriggerFieldGuard;
use App\Support\Watchers\WatcherResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakePresenceVerifier;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\Doubles\StaticRuleSegmentSource;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('rule-trigger-tenant');
    $this->objectTypeId = ModelStub::ulid('rule-trigger-type');

    $field = fn (string $key, FieldType $type, bool $isEncrypted = false): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid("rule-trigger-field-{$key}"),
        'object_type_id' => $this->objectTypeId,
        'key' => $key,
        'field_type' => $type,
        'is_encrypted' => $isEncrypted,
    ]);

    $lookup = Mockery::mock(ObjectTypeFieldLookup::class);
    $lookup->shouldReceive('fields')->with($this->objectTypeId)->andReturn(new EloquentCollection([
        $field('renewal_on', FieldType::Date),
        $field('contract_end', FieldType::Date),
        $field('stage', FieldType::SingleSelect),
        $field('salary', FieldType::Number),
        $field('tax_id', FieldType::TextShort, true),
    ]));

    $this->forbiddenByUser = new ArrayObject;

    $visibility = new class($this->forbiddenByUser) extends FieldVisibilityResolver
    {
        /**
         * @param  ArrayObject<string, list<string>>  $forbiddenByUser
         */
        public function __construct(private readonly ArrayObject $forbiddenByUser) {}

        /**
         * @return list<string>
         */
        public function forbiddenReadFieldKeys(User $user, string $objectTypeId): array
        {
            return $this->forbiddenByUser[(string) $user->getKey()] ?? [];
        }
    };

    $this->guard = new RuleTriggerFieldGuard($visibility, $lookup);

    $this->action = new SaveNotificationRuleAction(
        new RuleSegmentGuard(new StaticRuleSegmentSource),
        Mockery::mock(RuleMatcher::class),
        $this->guard,
    );

    $this->rule = fn (array $attributes = []): NotificationRule => ModelStub::make(NotificationRule::class, [
        'id' => ModelStub::ulid('rule-trigger-rule'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
        'name' => 'Vertragsende',
        'action' => json_encode(['notify' => true, 'create_reminder' => null]),
        ...$attributes,
    ]);

    $this->refusalOf = function (Closure $save): ?ValidationException {
        $connection = StaticQueryConnection::install(static fn (): array => [['aggregate' => 1]], static fn (): int => 1);

        try {
            $save();
        } catch (ValidationException $exception) {
            expect($connection->writtenStatements)->toBe([]);

            return $exception;
        } finally {
            StaticQueryConnection::uninstall();
        }

        return null;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses to create a date rule on a date field the creator may not read', function (): void {
    $creator = AccessContext::user($this->tenant, [], 'rule-trigger-creator');
    $this->forbiddenByUser[(string) $creator->getKey()] = ['contract_end'];
    FakePresenceVerifier::holding(['object_types' => [$this->objectTypeId]]);

    $refusal = ($this->refusalOf)(fn (): NotificationRule => $this->action->create([
        'object_type_id' => $this->objectTypeId,
        'name' => 'Vertragsende',
        'trigger_type' => 'date_based',
        'config' => ['date_field_key' => 'contract_end', 'lead_stages' => [['value' => 3, 'unit' => 'day']]],
    ], $creator));

    expect($refusal?->errors())->toBe([
        'config.date_field_key' => [__('i18n.backend.support.notifications.rule_trigger_field_guard.the_date_field_is_not_available')],
    ]);
});

it('refuses to update a change rule onto a watched field the editor may not read', function (): void {
    $editor = AccessContext::user($this->tenant, [], 'rule-trigger-editor');
    $this->forbiddenByUser[(string) $editor->getKey()] = ['salary'];

    $refusal = ($this->refusalOf)(fn (): NotificationRule => $this->action->update(($this->rule)(), [
        'trigger_type' => 'field_change',
        'config' => ['watched_field_keys' => ['stage', 'salary']],
    ], $editor));

    expect($refusal?->errors())->toBe([
        'config.watched_field_keys' => [__('i18n.backend.support.notifications.rule_trigger_field_guard.the_watched_fields_are_not_available')],
    ]);
});

it('refuses an encrypted trigger field even to an editor who may read it', function (array $config, string $errorKey): void {
    $editor = AccessContext::user($this->tenant, [], 'rule-trigger-editor');

    $refusal = ($this->refusalOf)(fn (): NotificationRule => $this->action->update(($this->rule)(), ['config' => $config], $editor));

    expect(array_keys($refusal?->errors() ?? []))->toBe([$errorKey]);
})->with([
    'an encrypted date field' => [['date_field_key' => 'tax_id', 'lead_stages' => []], 'config.date_field_key'],
    'an encrypted watched field' => [['watched_field_keys' => ['tax_id']], 'config.watched_field_keys'],
]);

it('saves trigger fields the editor may read', function (): void {
    $editor = AccessContext::user($this->tenant, [], 'rule-trigger-editor');
    $this->forbiddenByUser[(string) $editor->getKey()] = ['salary'];

    $refusal = ($this->refusalOf)(fn (): NotificationRule => $this->action->update(($this->rule)(), [
        'config' => ['date_field_key' => 'renewal_on', 'lead_stages' => [], 'watched_field_keys' => ['stage']],
    ], $editor));

    expect($refusal)->toBeNull();
});

it('delivers a rule triggered by a field only to the recipients who may read that field', function (array $config): void {
    Notification::fake();

    app()->instance(ChannelPreferenceResolver::class, new class extends ChannelPreferenceResolver
    {
        public function channelsFor(User $user, string $type): array
        {
            return [InboxChannel::class];
        }
    });

    $objectType = ModelStub::make(ObjectType::class, [
        'id' => $this->objectTypeId,
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'contracts',
    ]);
    $record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('rule-trigger-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
    ], ['objectType' => $objectType]);

    $reader = AccessContext::user($this->tenant, [], 'rule-trigger-reader');
    $outsider = AccessContext::user($this->tenant, [], 'rule-trigger-outsider');
    $this->forbiddenByUser[(string) $outsider->getKey()] = ['contract_end'];

    $watchers = new class(new Collection([$reader, $outsider])) extends WatcherResolver
    {
        /**
         * @param  Collection<int, User>  $recipients
         */
        public function __construct(private readonly Collection $recipients) {}

        public function recipientsFor(CustomRecord $record): Collection
        {
            return $this->recipients;
        }
    };

    StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);

    try {
        (new RuleActionRunner($watchers, app(CreateReminderTaskAction::class), $this->guard))
            ->run(($this->rule)(['config' => json_encode($config)]), $record, 'due');
    } finally {
        StaticQueryConnection::uninstall();
    }

    Notification::assertSentToTimes($reader, RuleNotification::class, 1);
    Notification::assertNotSentTo($outsider, RuleNotification::class);
})->with([
    'a date rule stored before the save check' => [['date_field_key' => 'contract_end', 'lead_stages' => [['value' => 1, 'unit' => 'day']]]],
    'a change rule stored before the save check' => [['watched_field_keys' => ['stage', 'contract_end']]],
]);
