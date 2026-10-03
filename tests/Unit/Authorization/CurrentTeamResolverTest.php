<?php

declare(strict_types=1);

use App\Models\Team;
use App\Support\Authorization\CurrentTeamResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->resolver = new CurrentTeamResolver;
});

afterEach(function (): void {
    AccessContext::forgetTeam();
    AccessContext::forgetTenant();
    Auth::forgetUser();
});

it('serves the team the request already bound without touching the database', function (): void {
    $team = AccessContext::team($this->tenant);

    expect(QueryShape::attemptedBy(fn (): ?Team => $this->resolver->resolve()))->toBeNull()
        ->and($this->resolver->resolve()?->getKey())->toBe($team->getKey())
        ->and($this->resolver->resolveKey())->toBe((string) $team->getKey());
});

it('looks the team of the request context up across tenants when only its id is known', function (): void {
    Context::addHidden('team_id', ModelStub::ulid('context-team'));

    $shape = QueryShape::attemptedBy(fn (): ?Team => $this->resolver->resolve());

    expect($shape)->not->toBeNull()
        ->and($shape->targets('teams'))->toBeTrue()
        ->and($shape->isKeyedTo('teams', ModelStub::ulid('context-team')))->toBeTrue()
        ->and($shape->isScopedToTenant('teams', (string) $this->tenant->getKey()))->toBeFalse();
});

it('checks the membership of a user the request context does not speak for', function (): void {
    Context::addHidden('team_id', ModelStub::ulid('context-team'));

    $user = AccessContext::user($this->tenant, ['current_team_id' => ModelStub::ulid('own-team')]);

    $shape = QueryShape::attemptedBy(fn (): ?Team => $this->resolver->resolve($user));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('teams'))->toBeTrue()
        ->and($shape->isKeyedTo('teams', ModelStub::ulid('context-team')))->toBeTrue()
        ->and($shape->sql)->toContain('"tenant_id" = ?')
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->sql)->toContain('exists (select * from "users"');
});

it('evaluates a foreign user against their own team while somebody else is acting', function (): void {
    $team = AccessContext::team($this->tenant);
    AccessContext::actAs(AccessContext::user($this->tenant, [], 'acting-user'));

    $foreign = AccessContext::user(
        $this->tenant,
        ['current_team_id' => ModelStub::ulid('own-team')],
        'foreign-user',
    );

    $shape = QueryShape::attemptedBy(fn (): ?Team => $this->resolver->resolve($foreign));

    expect($shape)->not->toBeNull()
        ->and($shape->isKeyedTo('teams', ModelStub::ulid('own-team')))->toBeTrue()
        ->and($shape->isKeyedTo('teams', (string) $team->getKey()))->toBeFalse()
        ->and($shape->sql)->toContain('exists (select * from "users"');
});

it('binds no team for a foreign user who has none of their own', function (): void {
    AccessContext::team($this->tenant);
    AccessContext::actAs(AccessContext::user($this->tenant, [], 'acting-user'));

    $foreign = AccessContext::user($this->tenant, ['current_team_id' => null], 'foreign-user');

    expect(QueryShape::attemptedBy(fn (): ?Team => $this->resolver->resolve($foreign)))->toBeNull()
        ->and($this->resolver->resolve($foreign))->toBeNull()
        ->and($this->resolver->resolveKey($foreign))->toBeNull();
});

it('serves the bound team to the acting user themselves', function (): void {
    $team = AccessContext::team($this->tenant);
    $actor = AccessContext::actAs(AccessContext::user($this->tenant, ['current_team_id' => ModelStub::ulid('own-team')]));

    expect(QueryShape::attemptedBy(fn (): ?Team => $this->resolver->resolve($actor)))->toBeNull()
        ->and($this->resolver->resolve($actor)?->getKey())->toBe($team->getKey());
});
