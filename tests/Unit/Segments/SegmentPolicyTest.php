<?php

declare(strict_types=1);

use App\Enums\Authorization\RoleAuthority;
use App\Models\Role;
use App\Models\Segment;
use App\Models\SegmentShare;
use App\Models\Team;
use App\Models\User;
use App\Policies\Segments\SegmentPolicy;
use App\Support\Authorization\TenantBoundary;
use App\Support\Segments\SegmentGrantSource;
use App\Support\Sharing\ShareGranteeMatcher;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->owner = RoleHolder::make(['tenant_id' => $this->tenant->getKey()], [], 'segment-owner');

    /** @var callable(string, list<Role>):RoleHolder */
    $this->member = fn (string $seed, array $roles = []): RoleHolder => RoleHolder::make(
        ['tenant_id' => $this->tenant->getKey()],
        $roles,
        $seed,
    );

    $this->escalatedRole = ModelStub::make(Role::class, [
        'id' => ModelStub::ulid('segment-scope-admin'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'scope-admin',
        'authority' => RoleAuthority::ScopeAdmin->value,
    ]);

    /** @var callable(array<string, mixed>):Segment */
    $this->segment = fn (array $attributes = []): Segment => ModelStub::make(Segment::class, [
        'id' => ModelStub::ulid('segment'),
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => $this->owner->getKey(),
        'name' => 'Meine Sicht',
        'object_type_id' => ModelStub::ulid('segment-type'),
        'is_system' => false,
        'is_default' => false,
        ...$attributes,
    ]);

    /** @var callable(string, string, bool):SegmentShare */
    $this->grant = fn (string $granteeType, string $granteeId, bool $canEdit = false): SegmentShare => ModelStub::make(
        SegmentShare::class,
        [
            'tenant_id' => $this->tenant->getKey(),
            'grantee_type' => $granteeType,
            'grantee_id' => $granteeId,
            'can_edit' => $canEdit,
        ],
    );

    /** @var callable(list<SegmentShare>, list<string>):SegmentPolicy */
    $this->policyWith = function (array $grants = [], array $teamIds = []): SegmentPolicy {
        $source = new class($grants, $teamIds) extends SegmentGrantSource
        {
            /**
             * @param  list<SegmentShare>  $grants
             * @param  list<string>  $teamIds
             */
            public function __construct(private readonly array $grants, private readonly array $teamIds) {}

            public function forSegment(Segment $segment): EloquentCollection
            {
                return new EloquentCollection($this->grants);
            }

            public function holdsTeam(User $user, string $teamId): bool
            {
                return in_array($teamId, $this->teamIds, true);
            }
        };

        return new SegmentPolicy(new TenantBoundary, $source, new ShareGranteeMatcher);
    };

    $this->policy = ($this->policyWith)();
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses every ability on a segment that belongs to another tenant', function (): void {
    $foreign = ($this->segment)(['tenant_id' => ModelStub::ulid('other-tenant')]);

    expect($this->policy->view($this->owner, $foreign))->toBeFalse()
        ->and($this->policy->update($this->owner, $foreign))->toBeFalse()
        ->and($this->policy->delete($this->owner, $foreign))->toBeFalse()
        ->and($this->policy->share($this->owner, $foreign))->toBeFalse()
        ->and($this->policy->manageDefaults($this->owner, $foreign))->toBeFalse();
});

it('grants the owner of a private segment view update delete and share', function (): void {
    $segment = ($this->segment)();

    expect($this->policy->view($this->owner, $segment))->toBeTrue()
        ->and($this->policy->update($this->owner, $segment))->toBeTrue()
        ->and($this->policy->delete($this->owner, $segment))->toBeTrue()
        ->and($this->policy->share($this->owner, $segment))->toBeTrue();
});

it('hides a private segment from a stranger of the same tenant', function (): void {
    $stranger = ($this->member)('stranger');

    expect($this->policy->view($stranger, ($this->segment)()))->toBeFalse()
        ->and($this->policy->update($stranger, ($this->segment)()))->toBeFalse()
        ->and($this->policy->delete($stranger, ($this->segment)()))->toBeFalse();
});

it('opens a system segment and an administrative default segment to every tenant member for reading', function (): void {
    $stranger = ($this->member)('stranger');

    expect($this->policy->view($stranger, ($this->segment)(['is_system' => true])))->toBeTrue()
        ->and($this->policy->view($stranger, ($this->segment)(['is_default' => true])))->toBeTrue();
});

it('vetoes writing sharing deleting and defaulting on a system segment even for its owner', function (): void {
    $system = ($this->segment)(['is_system' => true]);
    $admin = ($this->member)('admin', [$this->escalatedRole]);

    expect($this->policy->update($this->owner, $system))->toBeFalse()
        ->and($this->policy->delete($this->owner, $system))->toBeFalse()
        ->and($this->policy->share($this->owner, $system))->toBeFalse()
        ->and($this->policy->manageDefaults($this->owner, $system))->toBeFalse()
        ->and($this->policy->delete($admin, $system))->toBeFalse()
        ->and($this->policy->manageDefaults($admin, $system))->toBeFalse();
});

it('lets a directly granted user read the segment and write it only with the edit flag', function (): void {
    $reader = ($this->member)('reader');

    $readOnly = ($this->policyWith)([($this->grant)($reader->getMorphClass(), (string) $reader->getKey())]);
    $writable = ($this->policyWith)([($this->grant)($reader->getMorphClass(), (string) $reader->getKey(), true)]);

    $segment = ($this->segment)();

    expect($readOnly->view($reader, $segment))->toBeTrue()
        ->and($readOnly->update($reader, $segment))->toBeFalse()
        ->and($writable->view($reader, $segment))->toBeTrue()
        ->and($writable->update($reader, $segment))->toBeTrue();
});

it('never lets a granted editor delete or re-share the segment', function (): void {
    $editor = ($this->member)('editor');

    $policy = ($this->policyWith)([($this->grant)($editor->getMorphClass(), (string) $editor->getKey(), true)]);
    $segment = ($this->segment)();

    expect($policy->delete($editor, $segment))->toBeFalse()
        ->and($policy->share($editor, $segment))->toBeFalse();
});

it('reaches a grantee through their team membership and stops at a team they do not belong to', function (): void {
    $team = ModelStub::make(Team::class, ['id' => ModelStub::ulid('segment-team'), 'tenant_id' => $this->tenant->getKey()]);
    $member = ($this->member)('team-member');
    $segment = ($this->segment)();

    $granted = ($this->policyWith)(
        [($this->grant)($team->getMorphClass(), (string) $team->getKey(), true)],
        [(string) $team->getKey()],
    );

    $outsider = ($this->policyWith)(
        [($this->grant)($team->getMorphClass(), (string) $team->getKey(), true)],
        [],
    );

    expect($granted->view($member, $segment))->toBeTrue()
        ->and($granted->update($member, $segment))->toBeTrue()
        ->and($outsider->view($member, $segment))->toBeFalse();
});

it('reaches a grantee through a role they hold and stops at a role they do not hold', function (): void {
    $role = ModelStub::make(Role::class, [
        'id' => ModelStub::ulid('segment-role'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'segment-role',
    ]);

    $holder = ($this->member)('role-holder', [$role]);
    $nonHolder = ($this->member)('no-role');

    $policy = ($this->policyWith)([($this->grant)($role->getMorphClass(), (string) $role->getKey())]);
    $segment = ($this->segment)();

    expect($policy->view($holder, $segment))->toBeTrue()
        ->and($policy->view($nonHolder, $segment))->toBeFalse();
});

it('ignores a grant whose grantee type names no known holder', function (): void {
    $stranger = ($this->member)('stranger');

    $policy = ($this->policyWith)([($this->grant)(Segment::class, (string) $stranger->getKey(), true)]);

    expect($policy->view($stranger, ($this->segment)()))->toBeFalse();
});

it('never lets a grant reach the delete ability no matter how wide it is', function (): void {
    $editor = ($this->member)('editor');

    $policy = ($this->policyWith)([($this->grant)($editor->getMorphClass(), (string) $editor->getKey(), true)]);

    expect($policy->delete($editor, ($this->segment)()))->toBeFalse();
});

it('lets an elevated role delete a foreign segment but never read or change one it holds no grant on', function (): void {
    $admin = ($this->member)('admin', [$this->escalatedRole]);
    $segment = ($this->segment)();

    expect($this->policy->delete($admin, $segment))->toBeTrue()
        ->and($this->policy->share($admin, $segment))->toBeTrue()
        ->and($this->policy->view($admin, $segment))->toBeFalse()
        ->and($this->policy->update($admin, $segment))->toBeFalse();
});

it('reserves the administrative default for an elevated role and refuses the plain owner', function (): void {
    $admin = ($this->member)('admin', [$this->escalatedRole]);
    $segment = ($this->segment)();

    expect($this->policy->manageDefaults($admin, $segment))->toBeTrue()
        ->and($this->policy->manageDefaults($this->owner, $segment))->toBeFalse();
});
