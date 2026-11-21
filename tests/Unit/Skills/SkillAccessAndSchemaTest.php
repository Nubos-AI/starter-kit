<?php

declare(strict_types=1);

use App\Models\Skill;
use App\Policies\Skills\SkillPolicy;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->policy = new SkillPolicy;

    $this->skill = ModelStub::make(Skill::class, [
        'id' => ModelStub::ulid('skill'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Schweißen',
    ]);

    $this->schema = SchemaShape::ofMigration('database/migrations/0001_01_01_000059_create_skills_table.php');
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('grants a skill ability only to a holder of the matching permission', function (string $ability, string $permission): void {
    AccessContext::grant($permission);
    $permitted = AccessContext::user($this->tenant);

    $allowed = in_array($ability, ['viewAny', 'create'], true)
        ? $this->policy->{$ability}($permitted)
        : $this->policy->{$ability}($permitted, $this->skill);

    expect($allowed)->toBeTrue();

    AccessContext::grant('something.else');
    $refused = AccessContext::user($this->tenant, [], 'refused-user');

    $denied = in_array($ability, ['viewAny', 'create'], true)
        ? $this->policy->{$ability}($refused)
        : $this->policy->{$ability}($refused, $this->skill);

    expect($denied)->toBeFalse();
})->with([
    'listing' => ['viewAny', 'skills.view'],
    'viewing' => ['view', 'skills.view'],
    'creating' => ['create', 'skills.create'],
    'updating' => ['update', 'skills.update'],
    'deleting' => ['delete', 'skills.delete'],
]);

it('never lets a read permission open a write ability', function (): void {
    AccessContext::grant('skills.view');
    $reader = AccessContext::user($this->tenant);

    expect($this->policy->viewAny($reader))->toBeTrue()
        ->and($this->policy->create($reader))->toBeFalse()
        ->and($this->policy->update($reader, $this->skill))->toBeFalse()
        ->and($this->policy->delete($reader, $this->skill))->toBeFalse();
});

it('drops a skill together with its tenant', function (): void {
    expect($this->schema->hasForeignKey('skills', 'tenant_id', 'tenants', 'cascade'))->toBeTrue();
});

it('keeps the name unique per tenant only among the living rows', function (): void {
    expect($this->schema->hasPartialUniqueIndex(
        'unq_skills_tenant_name',
        'skills',
        ['tenant_id', 'name'],
        'deleted_at IS NULL',
    ))->toBeTrue();
});

it('lets a person hold a skill exactly once and drops the pair with either side', function (): void {
    $pivot = SchemaShape::ofMigration('database/migrations/0001_01_01_000060_create_skill_user_table.php');

    expect($pivot->has('add primary key ("skill_id", "user_id")'))->toBeTrue()
        ->and($pivot->hasForeignKey('skill_user', 'skill_id', 'skills', 'cascade'))->toBeTrue()
        ->and($pivot->hasForeignKey('skill_user', 'user_id', 'users', 'cascade'))->toBeTrue();
});

it('carries no soft delete on the assignment so a deleted skill keeps its holders', function (): void {
    $pivot = SchemaShape::ofMigration('database/migrations/0001_01_01_000060_create_skill_user_table.php');

    expect($pivot->columnsOf('skill_user'))->not->toContain('deleted_at');
});
