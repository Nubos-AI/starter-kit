<?php

declare(strict_types=1);

use App\Actions\Teams\UpsertTeamRecordAccessRuleAction;
use App\Enums\Teams\TeamAccessRuleInheritance;
use App\Models\ObjectType;
use App\Models\Team;
use App\Models\User;
use App\Support\Teams\TeamAccessRuleGuard;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('acme');
    $this->tenantId = (string) $this->tenant->getKey();

    $this->creator = ModelStub::make(User::class, ['id' => ModelStub::ulid('creator'), 'tenant_id' => $this->tenantId]);
    $this->editor = ModelStub::make(User::class, ['id' => ModelStub::ulid('editor'), 'tenant_id' => $this->tenantId]);
    $this->team = ModelStub::make(Team::class, ['id' => ModelStub::ulid('sales'), 'tenant_id' => $this->tenantId]);
    $this->objectType = ModelStub::make(ObjectType::class, ['id' => ModelStub::ulid('companies'), 'tenant_id' => $this->tenantId]);

    $this->filter = ['combinator' => 'and', 'rules' => []];

    $guard = Mockery::mock(TeamAccessRuleGuard::class);
    $guard->shouldReceive('assertValid');

    $this->action = new UpsertTeamRecordAccessRuleAction($guard);

    $this->storedRule = null;

    $this->connection = StaticQueryConnection::install(
        fn (string $sql): array => str_contains($sql, 'from "team_record_access_rules"') && $this->storedRule !== null
            ? [$this->storedRule]
            : [],
        static fn (): int => 1,
    );
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('keeps the activity, the inheritance and the creator of an existing rule when an update only sends the filter', function (): void {
    $this->storedRule = [
        'id' => ModelStub::ulid('rule'),
        'tenant_id' => $this->tenantId,
        'team_id' => ModelStub::ulid('sales'),
        'object_type_id' => ModelStub::ulid('companies'),
        'created_by_id' => ModelStub::ulid('creator'),
        'is_active' => false,
        'inheritance' => TeamAccessRuleInheritance::Override->value,
        'filter_definition' => json_encode(['combinator' => 'or', 'rules' => []]),
        'created_at' => '2026-10-01 08:00:00',
        'updated_at' => '2026-10-01 08:00:00',
        'deleted_at' => null,
    ];

    $rule = $this->action->execute($this->editor, $this->team, $this->objectType, ['filter_definition' => $this->filter]);

    $update = collect($this->connection->writtenStatements)->first(
        static fn (array $statement): bool => str_starts_with($statement['sql'], 'update "team_record_access_rules"'),
    );

    expect($rule->is_active)->toBeFalse()
        ->and($rule->inheritance)->toBe(TeamAccessRuleInheritance::Override)
        ->and($rule->created_by_id)->toBe(ModelStub::ulid('creator'))
        ->and($update)->not->toBeNull()
        ->and($update['sql'])->not->toContain('"is_active"')
        ->and($update['sql'])->not->toContain('"inheritance"')
        ->and($update['sql'])->not->toContain('"created_by_id"');
});

it('applies the defaults and the acting user as creator when the rule does not exist yet', function (): void {
    $rule = $this->action->execute($this->creator, $this->team, $this->objectType, ['filter_definition' => $this->filter]);

    expect($rule->is_active)->toBeTrue()
        ->and($rule->inheritance)->toBe(TeamAccessRuleInheritance::Intersect)
        ->and($rule->created_by_id)->toBe(ModelStub::ulid('creator'))
        ->and($this->connection->writtenSqlOf('team_record_access_rules')[0] ?? '')->toStartWith('insert into "team_record_access_rules"');
});
