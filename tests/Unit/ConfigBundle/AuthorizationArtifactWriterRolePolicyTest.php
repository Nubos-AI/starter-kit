<?php

declare(strict_types=1);

use App\Actions\Authorization\CreateRoleAction;
use App\Actions\Authorization\DeleteRoleAction;
use App\Actions\Authorization\SyncFieldPermissionsAction;
use App\Actions\Authorization\SyncRolePermissionsAction;
use App\Actions\Authorization\UpdateRoleAction;
use App\Actions\Teams\DeleteTeamRecordAccessRuleAction;
use App\Actions\Teams\UpsertTeamRecordAccessRuleAction;
use App\DTOs\ConfigBundle\BundleArtifact;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\ConfigBundle\ArtifactWriteAction;
use App\Enums\Promotion\DiffState;
use App\Handlers\ConfigBundle\AuthorizationArtifactWriter;
use App\Models\Role;
use App\Support\Authorization\RoleDeletionGuard;
use App\Support\ConfigBundle\BundleWriteTenant;
use App\Support\ConfigBundle\TargetKeyResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('role-policy-tenant');
    $this->tenantId = (string) $this->tenant->getKey();
    $this->actor = AccessContext::actAs(RoleHolder::make(['tenant_id' => $this->tenantId], [], 'role-policy-actor'));
    $this->roleId = ModelStub::ulid('system-role');
    $this->isSystem = true;

    AccessContext::grant('roles.update', 'roles.delete', 'config.import');

    $ids = [
        'roles|tenant:Administration' => $this->roleId,
        'field-definitions|deal:amount' => ModelStub::ulid('role-policy-field'),
    ];

    $targetKeys = Mockery::mock(TargetKeyResolver::class);
    $targetKeys->allows('idFor')->andReturnUsing(static function (ArtifactKind|string $kind, string $businessKey) use ($ids): ?string {
        $table = $kind instanceof ArtifactKind ? $kind->value : $kind;

        return $ids["{$table}|{$businessKey}"] ?? null;
    });
    $targetKeys->allows('invalidate');

    $this->updateRole = Mockery::mock(UpdateRoleAction::class);
    $this->deleteRole = Mockery::mock(DeleteRoleAction::class);
    $this->syncFieldPermissions = Mockery::mock(SyncFieldPermissionsAction::class);
    $this->roleDeletionGuard = Mockery::mock(RoleDeletionGuard::class);
    $this->roleDeletionGuard->allows('assignedUserCount')->andReturn(0);

    $this->writer = new AuthorizationArtifactWriter(
        new BundleWriteTenant,
        Mockery::mock(CreateRoleAction::class),
        $this->deleteRole,
        Mockery::mock(DeleteTeamRecordAccessRuleAction::class),
        $this->roleDeletionGuard,
        $this->syncFieldPermissions,
        Mockery::mock(SyncRolePermissionsAction::class),
        $targetKeys,
        $this->updateRole,
        Mockery::mock(UpsertTeamRecordAccessRuleAction::class),
    );

    $this->rename = fn () => $this->writer->apply(
        $this->actor,
        ArtifactKind::Roles,
        new BundleArtifact(ArtifactKind::Roles, 'tenant:Administration', []),
        DiffState::Modified,
    );

    $this->restrictField = function (): array {
        $this->writer->apply(
            $this->actor,
            ArtifactKind::FieldPermissions,
            new BundleArtifact(ArtifactKind::FieldPermissions, 'tenant:Administration:deal:amount', ['can_read' => true, 'can_write' => true]),
            DiffState::Added,
        );

        return $this->writer->flush($this->actor);
    };

    StaticQueryConnection::install(fn (string $sql): array => match (true) {
        str_contains($sql, 'from "roles"') => [[
            'id' => $this->roleId,
            'tenant_id' => $this->tenantId,
            'name' => 'Administration',
            'is_system' => $this->isSystem,
            'authority' => null,
        ]],
        str_contains($sql, 'count(') => [['aggregate' => 0]],
        default => [],
    });
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    Auth::forgetGuards();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('refuses to rename a system role for an importer without escalation', function (): void {
    $this->updateRole->shouldNotReceive('execute');

    expect($this->rename)->toThrow(AuthorizationException::class);
});

it('refuses to delete a system role for an importer without escalation', function (): void {
    $this->deleteRole->shouldNotReceive('execute');

    expect(fn () => $this->writer->remove($this->actor, ArtifactKind::Roles, 'tenant:Administration'))
        ->toThrow(AuthorizationException::class);
});

it('refuses to rewrite the field permissions of a system role for an importer without escalation', function (): void {
    $this->syncFieldPermissions->shouldNotReceive('execute');

    expect($this->restrictField)->toThrow(AuthorizationException::class);
});

it('asks the role policy before it updates a role and passes it on once allowed', function (): void {
    $gate = GateSpy::allowing('update');
    $this->isSystem = false;

    $this->updateRole->expects('execute')->andReturnUsing(static fn (object $actor, Role $role): Role => $role);

    $result = ($this->rename)();

    expect($result->action)->toBe(ArtifactWriteAction::Updated)
        ->and($gate->wasAskedFor('update', ModelStub::make(Role::class, ['id' => $this->roleId])))->toBeTrue();
});

it('asks the role policy before it deletes a role and passes it on once allowed', function (): void {
    $gate = GateSpy::allowing('delete');
    $this->isSystem = false;

    $this->deleteRole->expects('execute');

    $result = $this->writer->remove($this->actor, ArtifactKind::Roles, 'tenant:Administration');

    expect($result->action)->toBe(ArtifactWriteAction::Removed)
        ->and($gate->wasAskedFor('delete', ModelStub::make(Role::class, ['id' => $this->roleId])))->toBeTrue();
});

it('asks the role policy before it syncs field permissions and passes them on once allowed', function (): void {
    $gate = GateSpy::allowing('update');
    $this->isSystem = false;

    $this->syncFieldPermissions->expects('execute');

    $results = ($this->restrictField)();

    expect($results)->toHaveCount(1)
        ->and($results[0]->action)->toBe(ArtifactWriteAction::Updated)
        ->and($gate->wasAskedFor('update', ModelStub::make(Role::class, ['id' => $this->roleId])))->toBeTrue();
});
