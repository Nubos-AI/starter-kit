<?php

declare(strict_types=1);

use App\Enums\Audit\ActorType;
use App\Models\CustomRecord;
use App\Models\Role;
use App\Support\Approvals\ApprovalInvalidator;
use App\Support\Audit\ActorResolver;
use App\Support\Audit\AdminArtifactAuditor;
use App\Support\CustomFields\EncryptedFieldKeys;
use App\Support\Engine\AuditRecorder;
use App\Support\Engine\RecordChangeRelayStarter;
use App\Support\Engine\RecordDiff;
use App\Support\Timeline\ChangeTimelineWriter;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->user = AccessContext::user($this->tenant);

    $this->token = ModelStub::make(PersonalAccessToken::class, [
        'id' => 42,
        'name' => 'Zapier',
        'creator_id' => $this->user->getKey(),
    ]);

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('audited-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => ModelStub::ulid('audited-object-type'),
    ]);

    $encryptedKeys = Mockery::mock(EncryptedFieldKeys::class);
    $encryptedKeys->shouldReceive('forObjectType')->andReturn([]);

    $timeline = Mockery::mock(ChangeTimelineWriter::class);
    $timeline->shouldReceive('record');

    $approvals = Mockery::mock(ApprovalInvalidator::class);
    $approvals->shouldReceive('handleChange');

    $relay = Mockery::mock(RecordChangeRelayStarter::class);
    $relay->shouldReceive('startAfterCommit');

    $this->recorder = new AuditRecorder(app(ActorResolver::class), app(RecordDiff::class), $relay, $timeline, $encryptedKeys, $approvals);

    $this->auditor = new class(app(ActorResolver::class)) extends AdminArtifactAuditor
    {
        protected function nextVersion(Model $auditable): int
        {
            return 1;
        }
    };

    $this->connection = StaticQueryConnection::install(
        static fn (): array => [],
        static fn (): int => 1,
    );

    /** @var callable():array<string, mixed> */
    $this->auditRow = function (): array {
        $inserts = array_values(array_filter(
            $this->connection->writtenStatements,
            static fn (array $statement): bool => str_starts_with($statement['sql'], 'insert into "audit_entries"'),
        ));

        expect($inserts)->toHaveCount(1);

        preg_match('/insert into "audit_entries" \\(([^)]*)\\)/', $inserts[0]['sql'], $matches);

        $columns = array_map(
            static fn (string $column): string => trim($column, ' "'),
            explode(',', $matches[1]),
        );

        return array_combine($columns, array_slice($inserts[0]['bindings'], 0, count($columns)));
    };
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('files a record change made through an api token under the creator and names the token', function (): void {
    AccessContext::actAs($this->user->withAccessToken($this->token));

    $this->recorder->record($this->record, ['title' => 'A'], ['title' => 'B'], 2);

    $row = ($this->auditRow)();

    expect($row['actor_id'])->toBe((string) $this->user->getKey())
        ->and($row['actor_type'])->toBe(ActorType::User->value)
        ->and($row['api_token_id'])->toBe(42);
});

it('names no api token for a record change made in a browser session', function (): void {
    AccessContext::actAs($this->user->withAccessToken(new TransientToken));

    $this->recorder->record($this->record, ['title' => 'A'], ['title' => 'B'], 2);

    $row = ($this->auditRow)();

    expect($row['actor_id'])->toBe((string) $this->user->getKey())
        ->and($row['api_token_id'])->toBeNull();
});

it('names no api token for a record change an automation makes even while the token is attached', function (): void {
    AccessContext::actAs($this->user->withAccessToken($this->token));
    app()->instance('current_automation_actor', ['id' => ModelStub::ulid('automation'), 'type' => ActorType::Automation]);

    $this->recorder->record($this->record, ['title' => 'A'], ['title' => 'B'], 2);

    $row = ($this->auditRow)();

    expect($row['actor_type'])->toBe(ActorType::Automation->value)
        ->and($row['api_token_id'])->toBeNull();

    app()->forgetInstance('current_automation_actor');
});

it('names the api token on an administrative change and event made through it', function (string $kind): void {
    AccessContext::actAs($this->user->withAccessToken($this->token));
    $role = ModelStub::make(Role::class, ['id' => ModelStub::ulid('audited-role'), 'name' => 'operator']);

    $kind === 'event'
        ? $this->auditor->recordEvent($role, 'role.assigned', ['ok' => true])
        : $this->auditor->record($role, [], ['name' => 'operator']);

    $row = ($this->auditRow)();

    expect($row['actor_id'])->toBe((string) $this->user->getKey())
        ->and($row['api_token_id'])->toBe(42);
})->with(['change', 'event']);

it('names no api token on an administrative change made in a browser session', function (): void {
    AccessContext::actAs($this->user);
    $role = ModelStub::make(Role::class, ['id' => ModelStub::ulid('audited-role'), 'name' => 'operator']);

    $this->auditor->record($role, [], ['name' => 'operator']);

    expect(($this->auditRow)()['api_token_id'])->toBeNull();
});
