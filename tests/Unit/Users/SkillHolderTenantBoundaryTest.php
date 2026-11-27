<?php

declare(strict_types=1);

use App\Actions\Skills\SyncSkillUsersAction;
use App\Actions\Skills\UpdateSkillAction;
use App\Models\Skill;
use App\Support\Tenancy\TenantUserIdResolver;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticPresenceVerifier;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenantId = (string) AccessContext::tenant()->getKey();
    StaticPresenceVerifier::install([]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses a holder outside the tenant of the skill before it renames or reassigns anything', function (): void {
    $skill = ModelStub::make(Skill::class, ['id' => ModelStub::ulid('skill'), 'tenant_id' => $this->tenantId, 'name' => 'Statik']);
    $ownUser = ModelStub::ulid('own-user');
    $foreignUser = ModelStub::ulid('foreign-user');
    $holders = Mockery::mock(TenantUserIdResolver::class);
    $holders->shouldReceive('resolve')->once()->with($this->tenantId, [$ownUser, $foreignUser])->andReturn([$ownUser]);

    try {
        (new UpdateSkillAction(new SyncSkillUsersAction($holders)))->execute($skill, ['name' => 'Tragwerk', 'user_ids' => [$ownUser, $foreignUser]]);
        $this->fail('The foreign holder was accepted.');
    } catch (ValidationException $refused) {
        expect(array_keys($refused->errors()))->toBe(['user_ids'])
            ->and($skill->name)->toBe('Statik')
            ->and($skill->isDirty())->toBeFalse();
    }
});
