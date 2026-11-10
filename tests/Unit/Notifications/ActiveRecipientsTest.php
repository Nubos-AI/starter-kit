<?php

declare(strict_types=1);

use App\Actions\Import\FinalizeImportAction;
use App\Actions\Reminders\CreateReminderTaskAction;
use App\Console\Commands\ScanDueReminders;
use App\Console\Commands\SendNotificationDigests;
use App\Enums\Import\ImportJobStatus;
use App\Enums\Users\UserStatus;
use App\Models\CustomRecord;
use App\Models\NotificationRule;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Approvals\ApprovalSubjectSource;
use App\Support\Engine\BulkBatchReport;
use App\Support\Engine\BulkFinalizer;
use App\Support\Export\ExportFinalizer;
use App\Support\Import\PreparedImportRows;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Notifications\DigestRunner;
use App\Support\Notifications\RuleActionRunner;
use App\Support\Tenancy\ActingUserContext;
use App\Support\Tenancy\TenantBinder;
use App\Support\Timeline\ReminderTimelineWriter;
use App\Support\Watchers\WatcherDirectory;
use App\Support\Watchers\WatcherResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('active-recipients-tenant');
    $this->tenantId = (string) $this->tenant->getKey();
    $this->userId = ModelStub::ulid('active-recipients-user');

    $this->isActiveOnly = static fn (?QueryShape $shape): bool => $shape !== null
        && $shape->hasConjunctiveCondition('users', 'status')
        && $shape->hasBinding(UserStatus::Accepted->value);

    $this->usersQueriesOf = static fn (StaticQueryConnection $connection): array => array_values(array_map(
        static fn (array $query): QueryShape => new QueryShape($query['sql'], $connection->prepareBindings($query['bindings'])),
        array_filter($connection->queries, static fn (array $query): bool => str_contains($query['sql'], 'from "users"')),
    ));

    $this->lockRegistry = new class extends MaintenanceLockRegistry
    {
        public function __construct() {}

        public function lockedTenantIds(): array
        {
            return [];
        }
    };
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('narrows users to the ones whose account is active', function (): void {
    $shape = QueryShape::of(User::query()->active());

    expect(($this->isActiveOnly)($shape))->toBeTrue()
        ->and($shape->hasBinding(UserStatus::Blocked->value))->toBeFalse();
});

it('hands a record notification only to watchers and owners whose account is active', function (): void {
    $shape = QueryShape::attemptedBy(fn () => (new WatcherDirectory)->usersByIds($this->tenantId, [$this->userId]));

    expect(($this->isActiveOnly)($shape))->toBeTrue();
});

it('offers only users with an active account as new watchers', function (): void {
    $record = ModelStub::make(CustomRecord::class, ['id' => ModelStub::ulid('active-recipients-record'), 'tenant_id' => $this->tenantId]);

    $shape = QueryShape::attemptedBy(fn () => (new WatcherDirectory)->candidatesFor($record, [$this->userId]));

    expect(($this->isActiveOnly)($shape))->toBeTrue();
});

it('sends an approval notification only to recipients whose account is active', function (): void {
    $shape = QueryShape::attemptedBy(fn () => (new ApprovalSubjectSource(new TenantBinder))->recipients($this->tenantId, [$this->userId]));

    expect(($this->isActiveOnly)($shape))->toBeTrue();
});

it('mails a digest only to a user whose account is active', function (): void {
    $connection = StaticQueryConnection::install(
        fn (string $sql): array => match (true) {
            str_contains($sql, 'from "tenants"') => [['id' => $this->tenantId, 'name' => 'Digest', 'slug' => 'digest']],
            str_contains($sql, 'from "notification_digest_state"') => [['id' => ModelStub::ulid('active-digest-state'), 'tenant_id' => $this->tenantId, 'user_id' => $this->userId, 'frequency' => 'daily', 'hour' => 9, 'last_digest_sent_at' => '2026-11-08 08:00:00']],
            str_contains($sql, 'from "notification_inbox"') => [['id' => ModelStub::ulid('active-digest-inbox'), 'tenant_id' => $this->tenantId, 'user_id' => $this->userId, 'type' => 'reminder.due', 'data' => '{}', 'created_at' => '2026-11-09 07:00:00']],
            str_contains($sql, 'from "notification_type_defaults"') => [['type' => 'reminder.due', 'channel' => 'email', 'enabled' => true, 'delivery_mode' => 'digest']],
            default => [],
        },
        static fn (): int => 1,
    );

    (new DigestRunner)->run($this->tenantId, $this->userId, CarbonImmutable::parse('2026-11-09 09:00:00'));

    $usersQueries = ($this->usersQueriesOf)($connection);

    expect($usersQueries)->toHaveCount(1)
        ->and(($this->isActiveOnly)($usersQueries[0]))->toBeTrue();
});

it('enumerates due reminders only for assignees whose account is active', function (): void {
    $timelineWriter = Mockery::mock(ReminderTimelineWriter::class);

    $shape = QueryShape::attemptedBy(fn (): int => (new ScanDueReminders)->handle($timelineWriter, $this->lockRegistry));

    expect($shape?->targets('reminder_tasks'))->toBeTrue()
        ->and($shape?->sql)->toContain('exists (select * from "users"')
        ->and(($this->isActiveOnly)($shape))->toBeTrue();
});

it('enumerates digest settings only for users whose account is active', function (): void {
    $runner = Mockery::mock(DigestRunner::class);

    $shape = QueryShape::attemptedBy(fn (): int => (new SendNotificationDigests)->handle($runner, $this->lockRegistry));

    expect($shape?->targets('notification_digest_state'))->toBeTrue()
        ->and($shape?->sql)->toContain('exists (select * from "users"')
        ->and(($this->isActiveOnly)($shape))->toBeTrue();
});

it('tells only the owners and watchers whose account is active about a bulk change', function (): void {
    config()->set('cache.default', 'array');
    Notification::fake();
    $reportKey = ModelStub::ulid('active-bulk-report');
    BulkBatchReport::initProgress($reportKey, $this->tenantId, $this->userId, 1);
    $connection = StaticQueryConnection::install(
        fn (string $sql): array => match (true) {
            str_contains($sql, 'from "tenants"') => [['id' => $this->tenantId, 'name' => 'Bulk', 'slug' => 'bulk']],
            str_contains($sql, 'from "custom_records"') => [['owner_id' => ModelStub::ulid('active-bulk-owner')]],
            str_contains($sql, 'from "users"') => [['id' => $this->userId, 'tenant_id' => $this->tenantId, 'email' => 'bulk@acme.test', 'status' => UserStatus::Accepted->value]],
            default => [],
        },
        static fn (): int => 1,
    );

    app(BulkFinalizer::class)->finalize($this->tenantId, $this->userId, $reportKey, [ModelStub::ulid('active-bulk-a'), ModelStub::ulid('active-bulk-b')], 1, 'companies', true);

    $recipientQueries = array_values(array_filter(
        ($this->usersQueriesOf)($connection),
        static fn (QueryShape $shape): bool => $shape->hasBinding(ModelStub::ulid('active-bulk-owner')),
    ));

    expect($recipientQueries)->toHaveCount(1)
        ->and(($this->isActiveOnly)($recipientQueries[0]))->toBeTrue();
});

it('tells the requester about a finished export only while the account is active', function (): void {
    Notification::fake();
    $exportJobId = ModelStub::ulid('active-export-job');
    $connection = StaticQueryConnection::install(
        fn (string $sql): array => match (true) {
            str_contains($sql, 'from "tenants"') => [['id' => $this->tenantId, 'name' => 'Export']],
            str_contains($sql, 'from "export_jobs"') => [['id' => $exportJobId, 'tenant_id' => $this->tenantId, 'user_id' => $this->userId, 'status' => 'running', 'result_path' => 'exports/a.csv']],
            default => [],
        },
        static fn (): int => 1,
    );

    (new ExportFinalizer(new TenantBinder))->finalize($this->tenantId, $this->userId, $exportJobId);

    $usersQueries = ($this->usersQueriesOf)($connection);

    expect($usersQueries)->toHaveCount(1)
        ->and(($this->isActiveOnly)($usersQueries[0]))->toBeTrue();
    Notification::assertNothingSent();
});

it('tells the importer about a finished import only while the account is active', function (): void {
    Notification::fake();
    Storage::fake('imports');
    $importJobId = ModelStub::ulid('active-import-job');
    $connection = StaticQueryConnection::install(
        fn (string $sql): array => match (true) {
            str_contains($sql, 'from "tenants"') => [['id' => $this->tenantId, 'name' => 'Import']],
            str_contains($sql, 'from "users"') => [['id' => $this->userId, 'tenant_id' => $this->tenantId, 'email' => 'importer@acme.test', 'status' => UserStatus::Accepted->value]],
            str_contains($sql, 'from "import_jobs"') => [['id' => $importJobId, 'tenant_id' => $this->tenantId, 'user_id' => $this->userId, 'status' => ImportJobStatus::Running->value, 'source_disk' => 'imports', 'processed_rows' => 1, 'total_rows' => 1]],
            default => [],
        },
        static fn (): int => 1,
    );

    (new FinalizeImportAction(new ActingUserContext, new PreparedImportRows))->execute($this->tenantId, $this->userId, $importJobId);

    $usersQueries = ($this->usersQueriesOf)($connection);

    expect($usersQueries)->toHaveCount(2)
        ->and(($this->isActiveOnly)($usersQueries[1]))->toBeTrue();
});

it('creates no follow-up reminder in the name of a rule creator whose account is no longer active', function (UserStatus $status, bool $isCreated): void {
    Notification::fake();
    $objectType = ModelStub::make(ObjectType::class, ['id' => ModelStub::ulid('active-rule-type'), 'tenant_id' => $this->tenantId, 'slug' => 'companies']);
    $record = ModelStub::make(CustomRecord::class, ['id' => ModelStub::ulid('active-rule-record'), 'tenant_id' => $this->tenantId, 'object_type_id' => $objectType->getKey()], ['objectType' => $objectType]);
    $rule = ModelStub::make(NotificationRule::class, [
        'id' => ModelStub::ulid('active-rule'),
        'tenant_id' => $this->tenantId,
        'name' => 'Nachfassen',
        'action' => ['notify' => true, 'create_reminder' => ['subject' => 'Nachfassen']],
    ], ['creator' => AccessContext::user($this->tenant, ['status' => $status->value], 'active-rule-creator')]);
    StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);
    $reminders = Mockery::mock(CreateReminderTaskAction::class);
    $reminders->shouldReceive('execute')->times($isCreated ? 1 : 0);
    $resolver = new class extends WatcherResolver
    {
        public function __construct() {}

        public function recipientsFor(CustomRecord $record): Collection
        {
            return new Collection;
        }
    };

    (new RuleActionRunner($resolver, $reminders))->dispatchTo($rule, $record, AccessContext::user($this->tenant, [], 'active-rule-recipient'), 'due');
})->with([
    'an active creator' => [UserStatus::Accepted, true],
    'a blocked creator' => [UserStatus::Blocked, false],
    'a deleted creator' => [UserStatus::Deleted, false],
]);
