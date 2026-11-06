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
use App\Enums\Promotion\DiffState;
use App\Handlers\ConfigBundle\AuthorizationArtifactWriter;
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
    $this->tenant = AccessContext::tenant('flush-failure-tenant');
    $this->tenantId = (string) $this->tenant->getKey();
    $this->actor = AccessContext::actAs(RoleHolder::make(['tenant_id' => $this->tenantId], [], 'flush-failure-actor'));
    $this->roleId = ModelStub::ulid('flush-failure-role');

    $ids = [
        'permissions|deals:view' => ModelStub::ulid('flush-failure-permission'),
        'roles|tenant:Vertrieb' => $this->roleId,
        'field-definitions|deal:amount' => ModelStub::ulid('flush-failure-field'),
    ];

    $targetKeys = Mockery::mock(TargetKeyResolver::class);
    $targetKeys->allows('idFor')->andReturnUsing(static function (ArtifactKind|string $kind, string $businessKey) use ($ids): ?string {
        $table = $kind instanceof ArtifactKind ? $kind->value : $kind;

        return $ids["{$table}|{$businessKey}"] ?? null;
    });
    $targetKeys->allows('invalidate');

    $this->syncRolePermissions = Mockery::mock(SyncRolePermissionsAction::class);
    $this->syncFieldPermissions = Mockery::mock(SyncFieldPermissionsAction::class);

    $this->writer = new AuthorizationArtifactWriter(
        new BundleWriteTenant,
        Mockery::mock(CreateRoleAction::class),
        Mockery::mock(DeleteRoleAction::class),
        Mockery::mock(DeleteTeamRecordAccessRuleAction::class),
        Mockery::mock(RoleDeletionGuard::class),
        $this->syncFieldPermissions,
        $this->syncRolePermissions,
        $targetKeys,
        Mockery::mock(UpdateRoleAction::class),
        Mockery::mock(UpsertTeamRecordAccessRuleAction::class),
    );

    $this->collect = function (): void {
        $this->writer->apply(
            $this->actor,
            ArtifactKind::RolePermissions,
            new BundleArtifact(ArtifactKind::RolePermissions, 'tenant:Vertrieb:deals:view', []),
            DiffState::Added,
        );
        $this->writer->apply(
            $this->actor,
            ArtifactKind::FieldPermissions,
            new BundleArtifact(ArtifactKind::FieldPermissions, 'tenant:Vertrieb:deal:amount', ['can_read' => true, 'can_write' => false]),
            DiffState::Added,
        );
    };

    StaticQueryConnection::install(fn (string $sql): array => str_contains($sql, 'from "roles"')
        ? [['id' => $this->roleId, 'tenant_id' => $this->tenantId, 'name' => 'Vertrieb']]
        : []);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    Auth::forgetGuards();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('forgets every collected set when a flush throws so a retry cannot resync a stale target set', function (): void {
    ($this->collect)();

    $this->syncRolePermissions->shouldReceive('execute')->once()->andThrow(new RuntimeException('sync failed'));
    $this->syncFieldPermissions->shouldNotReceive('execute');

    expect(fn (): array => $this->writer->flush($this->actor))->toThrow(RuntimeException::class, 'sync failed')
        ->and($this->writer->flush($this->actor))->toBe([]);
});
