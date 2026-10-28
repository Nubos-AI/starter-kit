<?php

declare(strict_types=1);

use App\Actions\Skills\BulkDeleteSkillsAction;
use App\Actions\Skills\CreateSkillAction;
use App\Actions\Skills\DeleteSkillAction;
use App\Actions\Skills\SyncSkillUsersAction;
use App\Models\Skill;
use App\Models\User;
use App\Support\Tenancy\TenantUserIdResolver;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeTenantUserIdResolver;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\Support\WriteAttempt;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->actor = AccessContext::user($this->tenant);

    $this->createSkill = new CreateSkillAction;

    $this->skill = ModelStub::make(Skill::class, [
        'id' => ModelStub::ulid('skill'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Schweißen',
    ]);

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, list<string>>
     */
    $this->createErrorsOf = function (array $input, ?User $actor = null): array {
        try {
            $this->createSkill->execute($actor ?? $this->actor, $input);
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('the action accepted input it should have refused');
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    app()->forgetInstance(TenantUserIdResolver::class);
});

it('refuses to create a skill for an actor without a tenant and reads nothing', function (): void {
    $tenantless = ModelStub::make(User::class, ['id' => ModelStub::ulid('tenantless'), 'tenant_id' => null]);

    $errors = null;

    $shape = QueryShape::attemptedBy(function () use (&$errors, $tenantless): void {
        $errors = ($this->createErrorsOf)(['name' => 'Schweißen'], $tenantless);
    });

    expect($shape)->toBeNull()
        ->and($errors)->toHaveKey('name');
});

it('refuses a skill without a usable name before it asks the database', function (mixed $name): void {
    $errors = null;

    $shape = QueryShape::attemptedBy(function () use (&$errors, $name): void {
        $errors = ($this->createErrorsOf)(['name' => $name]);
    });

    expect($shape)->toBeNull()
        ->and($errors)->toHaveKey('name');
})->with([
    'missing' => null,
    'empty' => '',
]);

it('refuses a name beyond the column length before it asks the database', function (): void {
    $errors = null;

    $shape = QueryShape::attemptedBy(function () use (&$errors): void {
        $errors = ($this->createErrorsOf)(['name' => str_repeat('a', 256)]);
    });

    expect($shape)->toBeNull()
        ->and($errors)->toHaveKey('name');
});

it('checks the name for uniqueness inside the acting tenant and ignores soft deleted rows', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->createSkill->execute($this->actor, ['name' => 'Schweißen']));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('skills'))->toBeTrue()
        ->and($shape->sql)->toContain('"tenant_id" = ?')
        ->and($shape->sql)->toContain('"deleted_at" is null')
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hasBinding('Schweißen'))->toBeTrue();
});

it('refuses a user identifier that is not a ulid before it resolves any holder', function (): void {
    FakeTenantUserIdResolver::knowing([]);

    $errors = null;

    $shape = QueryShape::attemptedBy(function () use (&$errors): void {
        try {
            app(SyncSkillUsersAction::class)->execute($this->skill, ['not-a-ulid']);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
        }
    });

    expect($shape)->toBeNull()
        ->and($errors)->toHaveKey('user_ids.0');
});

it('refuses the whole assignment when one chosen person cannot hold a skill', function (): void {
    FakeTenantUserIdResolver::knowing([ModelStub::ulid('member')]);

    $errors = null;

    $shape = QueryShape::attemptedBy(function () use (&$errors): void {
        try {
            app(SyncSkillUsersAction::class)->execute($this->skill, [
                ModelStub::ulid('member'),
                ModelStub::ulid('outsider'),
            ]);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
        }
    });

    expect($shape)->toBeNull()
        ->and($errors)->toHaveKey('user_ids');
});

it('accepts a repeated identifier of a person who may hold the skill and writes the assignment once', function (): void {
    $resolver = Mockery::mock(TenantUserIdResolver::class);
    $resolver->shouldReceive('resolve')->andReturn([ModelStub::ulid('member')]);

    app()->instance(TenantUserIdResolver::class, $resolver);

    $refused = null;

    $reached = WriteAttempt::reachedTheDatabase(function () use (&$refused): void {
        try {
            app(SyncSkillUsersAction::class)->execute($this->skill, [
                ModelStub::ulid('member'),
                ModelStub::ulid('member'),
            ]);
        } catch (ValidationException $exception) {
            $refused = $exception->errors();
        }
    });

    expect($refused)->toBeNull()
        ->and($reached)->toBeTrue();
});

it('refuses a repeated identifier of a person who may not hold the skill', function (): void {
    FakeTenantUserIdResolver::knowing([]);

    $errors = null;

    QueryShape::attemptedBy(function () use (&$errors): void {
        try {
            app(SyncSkillUsersAction::class)->execute($this->skill, [
                ModelStub::ulid('outsider'),
                ModelStub::ulid('outsider'),
            ]);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
        }
    });

    expect($errors)->toHaveKey('user_ids');
});

it('resolves the holders against the tenant of the skill and not the acting tenant', function (): void {
    $resolver = Mockery::mock(TenantUserIdResolver::class);
    $resolver->shouldReceive('resolve')
        ->once()
        ->with(ModelStub::ulid('foreign-tenant'), [ModelStub::ulid('member')])
        ->andReturn([ModelStub::ulid('member')]);

    app()->instance(TenantUserIdResolver::class, $resolver);

    $foreignSkill = ModelStub::make(Skill::class, [
        'id' => ModelStub::ulid('foreign-skill'),
        'tenant_id' => ModelStub::ulid('foreign-tenant'),
        'name' => 'Löten',
    ]);

    expect(WriteAttempt::reachedTheDatabase(
        fn () => app(SyncSkillUsersAction::class)->execute($foreignSkill, [ModelStub::ulid('member')]),
    ))->toBeTrue();
});

it('blocks every row for a bulk delete by an actor without a tenant', function (): void {
    $action = new BulkDeleteSkillsAction(new DeleteSkillAction);

    $query = (new ReflectionMethod($action, 'query'))->invoke(
        $action,
        ModelStub::make(User::class, ['id' => ModelStub::ulid('tenantless'), 'tenant_id' => null]),
        null,
    );

    expect(QueryShape::of($query)->blocksEveryRow())->toBeTrue();
});

it('narrows a bulk delete to the skills of the acting tenant', function (): void {
    $action = new BulkDeleteSkillsAction(new DeleteSkillAction);

    $query = (new ReflectionMethod($action, 'query'))->invoke($action, $this->actor, null);

    $shape = QueryShape::of($query);

    expect($shape->blocksEveryRow())->toBeFalse()
        ->and($shape->targets('skills'))->toBeTrue()
        ->and($shape->isScopedToTenant('skills', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hidesSoftDeleted('skills'))->toBeTrue();
});
