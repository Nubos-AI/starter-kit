<?php

declare(strict_types=1);

use App\Actions\Authorization\CreateRoleAction;
use App\Actions\Authorization\DeleteRoleAction;
use App\Actions\Authorization\SyncFieldPermissionsAction;
use App\Actions\Authorization\SyncRolePermissionsAction;
use App\Actions\Authorization\UpdateRoleAction;
use App\Actions\Teams\DeleteTeamRecordAccessRuleAction;
use App\Actions\Teams\UpsertTeamRecordAccessRuleAction;
use App\DTOs\ConfigBundle\ArtifactWriteResult;
use App\DTOs\ConfigBundle\BundleArtifact;
use App\Enums\Authorization\RoleScope;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\ConfigBundle\ArtifactWriteAction;
use App\Enums\Promotion\DiffState;
use App\Exceptions\ConfigBundle\MissingActingUserException;
use App\Exceptions\ConfigBundle\UnsupportedArtifactKindException;
use App\Handlers\ConfigBundle\AuthorizationArtifactWriter;
use App\Models\Role;
use App\Support\Authorization\RoleDeletionGuard;
use App\Support\ConfigBundle\BundleWriteTenant;
use App\Support\ConfigBundle\TargetKeyResolver;
use Illuminate\Support\Facades\Auth;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();

    $this->actor = RoleHolder::make(['tenant_id' => $this->tenantId], [], 'authorization-actor');
    $this->stranger = RoleHolder::make(['tenant_id' => $this->tenantId], [], 'authorization-stranger');

    AccessContext::actAs($this->actor);

    $this->ids = new ArrayObject;
    $this->lookups = new ArrayObject;
    $this->invalidated = new ArrayObject;

    $targetKeys = Mockery::mock(TargetKeyResolver::class);

    $targetKeys->allows('idFor')->andReturnUsing(function (ArtifactKind|string $kind, string $businessKey): ?string {
        $table = $kind instanceof ArtifactKind ? $kind->value : $kind;

        $this->lookups[] = "{$table}|{$businessKey}";

        return $this->ids["{$table}|{$businessKey}"] ?? null;
    });

    $targetKeys->allows('invalidate')->andReturnUsing(function (ArtifactKind $kind): void {
        $this->invalidated[] = $kind->value;
    });

    $this->writeTenant = new BundleWriteTenant;

    $this->createRole = Mockery::mock(CreateRoleAction::class);
    $this->syncFieldPermissions = Mockery::mock(SyncFieldPermissionsAction::class);
    $this->syncRolePermissions = Mockery::mock(SyncRolePermissionsAction::class);

    $this->writer = new AuthorizationArtifactWriter(
        $this->writeTenant,
        $this->createRole,
        Mockery::mock(DeleteRoleAction::class),
        Mockery::mock(DeleteTeamRecordAccessRuleAction::class),
        Mockery::mock(RoleDeletionGuard::class),
        $this->syncFieldPermissions,
        $this->syncRolePermissions,
        $targetKeys,
        Mockery::mock(UpdateRoleAction::class),
        Mockery::mock(UpsertTeamRecordAccessRuleAction::class),
    );

    /** @var callable(ArtifactKind, string, array<string, mixed>, DiffState):ArtifactWriteResult */
    $this->apply = fn (ArtifactKind $kind, string $key, array $payload = [], DiffState $state = DiffState::Added): ArtifactWriteResult => $this->writer->apply(
        $this->actor,
        $kind,
        new BundleArtifact($kind, $key, $payload),
        $state,
    );

    /** @var callable(ArtifactWriteResult):string */
    $this->reasonOf = fn (ArtifactWriteResult $result): string => implode(' ', $result->notes);
});

afterEach(function (): void {
    Auth::forgetGuards();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('carries exactly the four artifact kinds of the authorization group', function (): void {
    $carried = array_values(array_filter(
        ArtifactKind::cases(),
        fn (ArtifactKind $kind): bool => $this->writer->supports($kind),
    ));

    expect($carried)->toBe([
        ArtifactKind::Roles,
        ArtifactKind::RolePermissions,
        ArtifactKind::FieldPermissions,
        ArtifactKind::TeamRecordAccessRules,
    ]);
});

it('refuses every entry point while nobody is authenticated', function (string $entryPoint): void {
    Auth::forgetGuards();

    $refusal = null;

    try {
        match ($entryPoint) {
            'apply' => ($this->apply)(ArtifactKind::Roles, 'tenant:Vertrieb'),
            'remove' => $this->writer->remove($this->actor, ArtifactKind::Roles, 'tenant:Vertrieb'),
            default => $this->writer->flush($this->actor),
        };
    } catch (MissingActingUserException $exception) {
        $refusal = $exception;
    }

    expect($refusal)->toBeInstanceOf(MissingActingUserException::class)
        ->and($refusal?->reason)->toBe('acting-user-not-authenticated');
})->with(['apply', 'remove', 'flush']);

it('refuses to write while somebody else is authenticated than the named acting user', function (): void {
    AccessContext::actAs($this->stranger);

    expect(fn (): ArtifactWriteResult => ($this->apply)(ArtifactKind::Roles, 'tenant:Vertrieb'))
        ->toThrow(MissingActingUserException::class);
});

it('refuses to write while the bound tenant differs from the open write frame', function (): void {
    $frameTenantId = ModelStub::ulid('authorization-foreign-tenant');

    $refusal = null;

    try {
        $this->writeTenant->within($frameTenantId, fn (): ArtifactWriteResult => ($this->apply)(ArtifactKind::Roles, 'tenant:Vertrieb'));
    } catch (MissingActingUserException $exception) {
        $refusal = $exception;
    }

    expect($refusal?->reason)->toBe('target-tenant-not-bound');
});

it('refuses to write while no target tenant is bound at all', function (): void {
    AccessContext::forgetTenant();

    $refusal = null;

    try {
        ($this->apply)(ArtifactKind::Roles, 'tenant:Vertrieb');
    } catch (MissingActingUserException $exception) {
        $refusal = $exception;
    }

    expect($refusal?->reason)->toBe('target-tenant-not-bound');
});

it('rejects an artifact kind that belongs to another writer with a typed exception', function (string $entryPoint): void {
    $call = $entryPoint === 'apply'
        ? fn (): ArtifactWriteResult => ($this->apply)(ArtifactKind::Automations, 'deal:Leadverteilung')
        : fn (): ArtifactWriteResult => $this->writer->remove($this->actor, ArtifactKind::Automations, 'deal:Leadverteilung');

    expect($call)->toThrow(UnsupportedArtifactKindException::class);
})->with(['apply', 'remove']);

it('skips a diff state that asks for no write and names the state in the reason', function (DiffState $state): void {
    $result = ($this->apply)(ArtifactKind::Roles, 'tenant:Vertrieb', [], $state);

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and(($this->reasonOf)($result))->toContain($state->value)
        ->and($this->invalidated->getArrayCopy())->toBe([]);
})->with([
    'unchanged' => DiffState::Unchanged,
    'removed' => DiffState::Removed,
    'conflicted' => DiffState::Conflicted,
]);

it('skips a role key that carries the wrong number of components', function (string $key): void {
    $result = ($this->apply)(ArtifactKind::Roles, $key);

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and(($this->reasonOf)($result))->toContain($key)
        ->and($this->invalidated->getArrayCopy())->toBe([]);
})->with([
    'one component' => 'Vertrieb',
    'three components' => 'tenant:Vertrieb:extra',
]);

it('names the unknown scope instead of blaming the number of components of a well formed role key', function (): void {
    $result = ($this->apply)(ArtifactKind::Roles, 'mandant:Vertrieb');

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and(($this->reasonOf)($result))->toContain('mandant');
});

it('creates a role with the name and the scope taken from its business key', function (): void {
    $role = ModelStub::make(Role::class, ['id' => ModelStub::ulid('created-role')]);

    $this->createRole->expects('execute')
        ->withArgs(function (object $actor, array $input): bool {
            expect($actor)->toBe($this->actor)
                ->and($input)->toBe([
                    'name' => 'Vertrieb',
                    'scope' => RoleScope::Tenant->value,
                    'authority' => null,
                    'grants_subteam_visibility' => true,
                ]);

            return true;
        })
        ->andReturn($role);

    $result = ($this->apply)(ArtifactKind::Roles, 'tenant:Vertrieb', [
        'name' => 'Aus der Nutzlast',
        'grants_subteam_visibility' => true,
    ]);

    expect($result->action)->toBe(ArtifactWriteAction::Created)
        ->and($result->modelId)->toBe((string) $role->getKey())
        ->and($result->notes)->toBe([])
        ->and($this->invalidated->getArrayCopy())->toBe([ArtifactKind::Roles->value]);
});

it('creates a role that declares itself a system role without the system flag and says so', function (): void {
    $this->createRole->expects('execute')
        ->withArgs(function (object $actor, array $input): bool {
            expect($input)->not->toHaveKey('is_system');

            return true;
        })
        ->andReturn(ModelStub::make(Role::class, ['id' => ModelStub::ulid('created-system-role')]));

    $result = ($this->apply)(ArtifactKind::Roles, 'tenant:Vertrieb', ['is_system' => true]);

    expect($result->action)->toBe(ArtifactWriteAction::Created)
        ->and($result->notes)->not->toBe([]);
});

it('skips a role that the key does not name in the target and writes no new row', function (): void {
    $result = ($this->apply)(ArtifactKind::Roles, 'tenant:Vertrieb', [], DiffState::Modified);

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and(($this->reasonOf)($result))->toContain('tenant:Vertrieb')
        ->and($this->invalidated->getArrayCopy())->toBe([]);
});

it('reports a role removal whose key names nothing in the target as a missing target', function (): void {
    $result = $this->writer->remove($this->actor, ArtifactKind::Roles, 'tenant:Vertrieb');

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and(($this->reasonOf)($result))->toContain('tenant:Vertrieb');
});

it('splits a role permission key by the offsets the writer declares', function (): void {
    $result = ($this->apply)(ArtifactKind::RolePermissions, 'tenant:Vertrieb:deals:view');

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and($result->awaitsReference())->toBeTrue()
        ->and(($this->reasonOf)($result))->toContain('role_id')
        ->and(($this->reasonOf)($result))->toContain('tenant:Vertrieb')
        ->and($this->lookups->getArrayCopy())->toContain('roles|tenant:Vertrieb');
});

it('skips a role permission key whose component count the writer does not allow', function (string $key): void {
    $result = ($this->apply)(ArtifactKind::RolePermissions, $key);

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and(($this->reasonOf)($result))->toContain($key)
        ->and($this->lookups->getArrayCopy())->toBe([]);
})->with([
    'three components' => 'tenant:Vertrieb:deals',
    'five components' => 'tenant:Vertrieb:deals:view:extra',
]);

it('skips a permission the target does not know and names the permission behind the role', function (): void {
    $this->ids['roles|tenant:Vertrieb'] = ModelStub::ulid('role');

    $result = ($this->apply)(ArtifactKind::RolePermissions, 'tenant:Vertrieb:deals:view');

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and(($this->reasonOf)($result))->toContain('permission_id')
        ->and(($this->reasonOf)($result))->toContain('deals:view')
        ->and($this->lookups->getArrayCopy())->toContain('permissions|deals:view');
});

it('collects a role permission without writing a single row before the flush', function (DiffState $state, ArtifactWriteAction $action): void {
    $roleId = ModelStub::ulid('role');
    $this->ids['roles|tenant:Vertrieb'] = $roleId;
    $this->ids['permissions|deals:view'] = ModelStub::ulid('permission');

    $result = ($this->apply)(ArtifactKind::RolePermissions, 'tenant:Vertrieb:deals:view', [], $state);

    expect($result->action)->toBe($action)
        ->and($result->modelId)->toBe($roleId)
        ->and(($this->reasonOf)($result))->toContain('deals:view')
        ->and($this->invalidated->getArrayCopy())->toBe([]);
})->with([
    'added' => [DiffState::Added, ArtifactWriteAction::Created],
    'modified' => [DiffState::Modified, ArtifactWriteAction::Updated],
]);

it('reports a given up role permission as removed without touching a row', function (): void {
    $roleId = ModelStub::ulid('role');
    $this->ids['roles|tenant:Vertrieb'] = $roleId;
    $this->ids['permissions|deals:view'] = ModelStub::ulid('permission');

    $result = $this->writer->remove($this->actor, ArtifactKind::RolePermissions, 'tenant:Vertrieb:deals:view');

    expect($result->action)->toBe(ArtifactWriteAction::Removed)
        ->and($result->modelId)->toBe($roleId)
        ->and($this->invalidated->getArrayCopy())->toBe([]);
});

it('splits a field permission key behind the two role components and keeps the rest as the field key', function (): void {
    $this->ids['roles|tenant:Vertrieb'] = ModelStub::ulid('role');

    $result = ($this->apply)(ArtifactKind::FieldPermissions, 'tenant:Vertrieb:deal:amount');

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and(($this->reasonOf)($result))->toContain('field_definition_id')
        ->and($this->lookups->getArrayCopy())->toContain('field-definitions|deal:amount');
});

it('collects a field permission with the flags of the artifact and writes nothing before the flush', function (): void {
    $roleId = ModelStub::ulid('role');
    $this->ids['roles|tenant:Vertrieb'] = $roleId;
    $this->ids['field-definitions|deal:amount'] = ModelStub::ulid('field');

    $result = ($this->apply)(ArtifactKind::FieldPermissions, 'tenant:Vertrieb:deal:amount', [
        'can_read' => true,
        'can_write' => false,
    ]);

    expect($result->action)->toBe(ArtifactWriteAction::Created)
        ->and($result->modelId)->toBe($roleId);
});

it('says that a field permission cannot be given up through a bundle', function (): void {
    $result = $this->writer->remove($this->actor, ArtifactKind::FieldPermissions, 'tenant:Vertrieb:deal:amount');

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and(($this->reasonOf)($result))->toContain('tenant:Vertrieb:deal:amount');
});

it('reports one flush result per collected role and none for a role the target lost', function (): void {
    $this->ids['permissions|deals:view'] = ModelStub::ulid('permission');
    $this->ids['field-definitions|deal:amount'] = ModelStub::ulid('field');
    $this->ids['roles|tenant:Vertrieb'] = ModelStub::ulid('sales-role');
    $this->ids['roles|tenant:Abrechnung'] = ModelStub::ulid('billing-role');

    ($this->apply)(ArtifactKind::RolePermissions, 'tenant:Vertrieb:deals:view');
    ($this->apply)(ArtifactKind::RolePermissions, 'tenant:Abrechnung:deals:view');
    ($this->apply)(ArtifactKind::FieldPermissions, 'tenant:Vertrieb:deal:amount');

    $this->ids['roles|tenant:Vertrieb'] = null;
    $this->ids['roles|tenant:Abrechnung'] = null;

    $results = $this->writer->flush($this->actor);

    expect(array_map(fn (ArtifactWriteResult $result): string => $result->key, $results))
        ->toBe(['tenant:Abrechnung', 'tenant:Vertrieb', 'tenant:Vertrieb'])
        ->and(array_map(fn (ArtifactWriteResult $result): ArtifactKind => $result->kind, $results))
        ->toBe([ArtifactKind::RolePermissions, ArtifactKind::RolePermissions, ArtifactKind::FieldPermissions])
        ->and(array_values(array_unique(array_map(fn (ArtifactWriteResult $result): string => $result->action->value, $results))))
        ->toBe([ArtifactWriteAction::Skipped->value]);
});

it('answers a second flush without a new apply with an empty list', function (): void {
    $this->ids['permissions|deals:view'] = ModelStub::ulid('permission');
    $this->ids['roles|tenant:Vertrieb'] = ModelStub::ulid('sales-role');

    ($this->apply)(ArtifactKind::RolePermissions, 'tenant:Vertrieb:deals:view');

    $this->ids['roles|tenant:Vertrieb'] = null;

    expect($this->writer->flush($this->actor))->toHaveCount(1)
        ->and($this->writer->flush($this->actor))->toBe([]);
});

it('skips a team record access rule whose team is missing in the target', function (): void {
    $result = ($this->apply)(ArtifactKind::TeamRecordAccessRules, 'Vertriebsteam:deal', [
        'filter_definition' => ['combinator' => 'and', 'conditions' => []],
    ]);

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and($result->awaitsReference())->toBeTrue()
        ->and(($this->reasonOf)($result))->toContain('team_id');
});

it('skips a team record access rule whose key names a team but no object type', function (): void {
    $result = ($this->apply)(ArtifactKind::TeamRecordAccessRules, 'Vertriebsteam', [
        'filter_definition' => [],
    ]);

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and($this->lookups->getArrayCopy())->toBe([]);
});

it('reads every component behind the team as the object type so a regional object type with a colon is found', function (): void {
    ($this->apply)(ArtifactKind::TeamRecordAccessRules, 'Vertriebsteam:region:deal', [
        'filter_definition' => [],
    ]);

    expect($this->lookups->getArrayCopy())->toBe([
        'teams|Vertriebsteam',
        'object-types|region:deal',
    ]);
});

it('reports a team record access rule removal whose key names nothing as a missing target', function (): void {
    $result = $this->writer->remove($this->actor, ArtifactKind::TeamRecordAccessRules, 'Vertriebsteam:deal');

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and(($this->reasonOf)($result))->toContain('Vertriebsteam:deal');
});

it('leaves every write to its actions and opens no transaction of its own', function (): void {
    $this->createRole->expects('execute')
        ->andReturn(ModelStub::make(Role::class, ['id' => ModelStub::ulid('untouched-role')]));

    $connection = StaticQueryConnection::install(static fn (): array => []);

    $result = ($this->apply)(ArtifactKind::Roles, 'tenant:Vertrieb', [
        'grants_subteam_visibility' => true,
    ]);

    StaticQueryConnection::uninstall();

    expect($result->action)->toBe(ArtifactWriteAction::Created)
        ->and($connection->writtenStatements)->toBe([])
        ->and($connection->openedTransactions)->toBe(0)
        ->and($connection->queries)->toBe([]);
});
