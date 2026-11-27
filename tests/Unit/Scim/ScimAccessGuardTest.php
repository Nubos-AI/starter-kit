<?php

declare(strict_types=1);

use App\Enums\Users\UserStatus;
use App\Models\ScimToken;
use App\Models\User;
use App\Support\Authorization\AuthorizationDirectory;
use App\Support\Authorization\PermissionSubsetGuard;
use App\Support\Scim\ScimAccessGuard;
use App\Support\Scim\ScimUserDirectory;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('acme');
    $this->tenantId = (string) $this->tenant->getKey();

    $this->guard = new ScimAccessGuard(
        Mockery::mock(PermissionSubsetGuard::class),
        Mockery::mock(AuthorizationDirectory::class),
        Mockery::mock(ScimUserDirectory::class),
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('treats a token as orphaned once its creator is no longer entitled', function (bool $hasCreator, string $tenantSeed, UserStatus $status, string $grantedPermission, bool $isEntitled): void {
    AccessContext::grant($grantedPermission);

    $creator = $hasCreator ? ModelStub::make(User::class, [
        'id' => ModelStub::ulid('helpdesk'),
        'tenant_id' => $tenantSeed === 'acme' ? $this->tenantId : ModelStub::ulid($tenantSeed),
        'status' => $status->value,
    ]) : null;

    $token = ModelStub::make(ScimToken::class, [
        'id' => ModelStub::ulid('token'),
        'tenant_id' => $this->tenantId,
        'created_by_id' => $creator?->getKey(),
    ], ['creator' => $creator]);

    expect($this->guard->hasEntitledCreator($token))->toBe($isEntitled);
})->with([
    'entitled creator' => [true, 'acme', UserStatus::Accepted, 'sso.manage', true],
    'no creator' => [false, 'acme', UserStatus::Accepted, 'sso.manage', false],
    'blocked creator' => [true, 'acme', UserStatus::Blocked, 'sso.manage', false],
    'creator of another tenant' => [true, 'globex', UserStatus::Accepted, 'sso.manage', false],
    'creator who lost the sso permission' => [true, 'acme', UserStatus::Accepted, 'members.view', false],
]);
