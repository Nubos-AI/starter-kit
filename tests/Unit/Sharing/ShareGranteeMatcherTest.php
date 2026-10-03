<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Support\Sharing\ShareGranteeMatcher;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->matcher = new ShareGranteeMatcher;

    $this->role = ModelStub::make(Role::class, ['id' => ModelStub::ulid('matcher-role'), 'name' => 'viewer']);
    $this->team = ModelStub::make(Team::class, ['id' => ModelStub::ulid('matcher-team'), 'name' => 'Field']);

    $this->viewer = RoleHolder::make([], [$this->role], 'matcher-viewer');
    $this->viewer->setRelation('teams', new EloquentCollection([$this->team]));

    $this->teamMorph = (new Team)->getMorphClass();
    $this->roleMorph = (new Role)->getMorphClass();
});

it('matches a grant addressed to the viewer personally and refuses one addressed to someone else', function (): void {
    expect($this->matcher->matches(User::class, (string) $this->viewer->getKey(), $this->viewer))->toBeTrue()
        ->and($this->matcher->matches(User::class, ModelStub::ulid('someone-else'), $this->viewer))->toBeFalse();
});

it('matches a team grant through the loaded team membership of the viewer', function (): void {
    expect($this->matcher->matches($this->teamMorph, (string) $this->team->getKey(), $this->viewer))->toBeTrue()
        ->and($this->matcher->matches($this->teamMorph, ModelStub::ulid('foreign-team'), $this->viewer))->toBeFalse();
});

it('asks the supplied membership check instead of the loaded teams when one is given', function (): void {
    $asked = [];
    $holdsTeam = function (string $teamId) use (&$asked): bool {
        $asked[] = $teamId;

        return $teamId === ModelStub::ulid('queried-team');
    };

    expect($this->matcher->matches($this->teamMorph, ModelStub::ulid('queried-team'), $this->viewer, $holdsTeam))->toBeTrue()
        ->and($this->matcher->matches($this->teamMorph, (string) $this->team->getKey(), $this->viewer, $holdsTeam))->toBeFalse()
        ->and($asked)->toBe([ModelStub::ulid('queried-team'), (string) $this->team->getKey()]);
});

it('matches a role grant through the roles the viewer holds', function (): void {
    expect($this->matcher->matches($this->roleMorph, (string) $this->role->getKey(), $this->viewer))->toBeTrue()
        ->and($this->matcher->matches($this->roleMorph, ModelStub::ulid('foreign-role'), $this->viewer))->toBeFalse();
});

it('refuses a grantee type it does not know', function (): void {
    expect($this->matcher->matches('App\\Models\\Tenant', (string) $this->viewer->getKey(), $this->viewer))->toBeFalse();
});
