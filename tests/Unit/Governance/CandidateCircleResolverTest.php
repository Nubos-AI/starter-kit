<?php

declare(strict_types=1);

use App\DTOs\Governance\CandidateCircle;
use App\Enums\Governance\CandidateSource;
use App\Models\CustomRecord;
use App\Models\User;
use App\Support\Governance\CandidateCircleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticAbsenceDelegationDirectory;
use Tests\Support\Doubles\StaticCandidateDirectory;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();

    $this->directory = StaticCandidateDirectory::empty();
    $this->absences = StaticAbsenceDelegationDirectory::covering([]);

    $this->at = CarbonImmutable::parse('2026-09-15T09:00:00+00:00');

    $this->roleId = ModelStub::ulid('role');
    $this->namedTeamId = ModelStub::ulid('named-team');
    $this->recordTeamId = ModelStub::ulid('record-team');

    /** @var callable(string, array<string, mixed>):User */
    $this->user = fn (string $seed, array $overrides = []): User => ModelStub::make(User::class, [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => $this->tenantId,
        ...$overrides,
    ]);

    /** @var callable(array<string, mixed>):CustomRecord */
    $this->record = fn (array $overrides = []): CustomRecord => ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('record'),
        'tenant_id' => $this->tenantId,
        'team_id' => null,
        'owner_id' => null,
        'data' => [],
        ...$overrides,
    ]);

    /** @var callable(array<string, mixed>):CandidateCircle */
    $this->circle = fn (array $overrides = []): CandidateCircle => new CandidateCircle(
        sources: $overrides['sources'] ?? [],
        roleIds: $overrides['roleIds'] ?? [],
        teamIds: $overrides['teamIds'] ?? [],
        includeRecordTeam: $overrides['includeRecordTeam'] ?? false,
        fieldKey: $overrides['fieldKey'] ?? null,
        userIds: $overrides['userIds'] ?? [],
    );

    $this->resolver = fn (): CandidateCircleResolver => app(CandidateCircleResolver::class);

    /** @var callable(Collection<int, User>):list<string> */
    $this->ids = static fn (Collection $users): array => array_values($users
        ->map(static fn (User $user): string => (string) $user->getKey())
        ->all());
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('asks every source with the tenant of the record and never with the ambient one', function (): void {
    $foreignTenantId = ModelStub::ulid('foreign-tenant');

    $record = ($this->record)([
        'tenant_id' => $foreignTenantId,
        'team_id' => $this->recordTeamId,
    ]);

    ($this->resolver)()->resolve($record, ($this->circle)([
        'sources' => [CandidateSource::Role, CandidateSource::Team, CandidateSource::FixedList],
        'roleIds' => [$this->roleId],
        'teamIds' => [$this->namedTeamId],
        'userIds' => [ModelStub::ulid('listed')],
    ]), $this->at);

    expect($this->directory->requestedTenantIds())->toBe([$foreignTenantId])
        ->and($this->directory->requestedTenantIds())->not->toContain($this->tenantId);
});

it('scopes the role lookup to the team of the record', function (): void {
    $record = ($this->record)(['team_id' => $this->recordTeamId]);

    ($this->resolver)()->resolve($record, ($this->circle)([
        'sources' => [CandidateSource::Role],
        'roleIds' => [$this->roleId],
    ]), $this->at);

    expect($this->directory->roleLookups)->toHaveCount(1)
        ->and($this->directory->roleLookups[0]['roleIds'])->toBe([$this->roleId])
        ->and($this->directory->roleLookups[0]['teamId'])->toBe($this->recordTeamId);
});

it('names no team scope for a record without a team and for the record free circle', function (): void {
    ($this->resolver)()->resolve(($this->record)(), ($this->circle)([
        'sources' => [CandidateSource::Role],
        'roleIds' => [$this->roleId],
    ]), $this->at);

    ($this->resolver)()->resolveWithoutRecord(($this->circle)([
        'sources' => [CandidateSource::Role],
        'roleIds' => [$this->roleId],
    ]), $this->tenantId, $this->at);

    expect($this->directory->roleLookups[0]['teamId'])->toBeNull()
        ->and($this->directory->roleLookups[1]['teamId'])->toBeNull();
});

it('never asks for a role while the role source is switched off', function (): void {
    ($this->resolver)()->resolve(($this->record)(), ($this->circle)([
        'sources' => [CandidateSource::FixedList],
        'roleIds' => [$this->roleId],
        'userIds' => [ModelStub::ulid('listed')],
    ]), $this->at);

    expect($this->directory->roleLookups)->toBe([]);
});

it('adds the record team to the named teams only when it was asked for', function (): void {
    $record = ($this->record)(['team_id' => $this->recordTeamId]);

    $resolver = ($this->resolver)();

    $resolver->resolve($record, ($this->circle)([
        'sources' => [CandidateSource::Team],
        'teamIds' => [$this->namedTeamId],
    ]), $this->at);

    $resolver->resolve($record, ($this->circle)([
        'sources' => [CandidateSource::Team],
        'teamIds' => [$this->namedTeamId],
        'includeRecordTeam' => true,
    ]), $this->at);

    expect($this->directory->teamLookups[0]['teamIds'])->toBe([$this->namedTeamId])
        ->and($this->directory->teamLookups[1]['teamIds'])->toBe([$this->namedTeamId, $this->recordTeamId]);
});

it('contributes no team for a record without a team although the record team was asked for', function (): void {
    ($this->resolver)()->resolve(($this->record)(), ($this->circle)([
        'sources' => [CandidateSource::Team],
        'includeRecordTeam' => true,
    ]), $this->at);

    expect($this->directory->teamLookups[0]['teamIds'])->toBe([]);
});

it('never asks for a team while the team source is switched off', function (): void {
    ($this->resolver)()->resolve(($this->record)(['team_id' => $this->recordTeamId]), ($this->circle)([
        'sources' => [CandidateSource::FixedList],
        'teamIds' => [$this->namedTeamId],
        'includeRecordTeam' => true,
        'userIds' => [ModelStub::ulid('listed')],
    ]), $this->at);

    expect($this->directory->teamLookups[0]['teamIds'])->toBe([]);
});

it('reads the record team through the team_id field key only while the field source is on', function (): void {
    $record = ($this->record)(['team_id' => $this->recordTeamId]);

    $resolver = ($this->resolver)();

    $resolver->resolve($record, ($this->circle)([
        'sources' => [CandidateSource::Field],
        'fieldKey' => 'team_id',
    ]), $this->at);

    $resolver->resolve($record, ($this->circle)([
        'sources' => [CandidateSource::FixedList],
        'fieldKey' => 'team_id',
        'userIds' => [ModelStub::ulid('listed')],
    ]), $this->at);

    expect($this->directory->teamLookups[0]['teamIds'])->toBe([$this->recordTeamId])
        ->and($this->directory->teamLookups[1]['teamIds'])->toBe([]);
});

it('takes the owner of the record for the owner field key', function (): void {
    $owner = ($this->user)('owner');
    $this->directory->withActiveUsers([$owner]);

    $resolved = ($this->resolver)()->resolve(($this->record)([
        'owner_id' => (string) $owner->getKey(),
    ]), ($this->circle)([
        'sources' => [CandidateSource::Field],
        'fieldKey' => 'owner_id',
    ]), $this->at);

    expect(($this->ids)($resolved))->toBe([(string) $owner->getKey()]);
});

it('takes a single identifier and a list of identifiers out of a data field', function (): void {
    $single = ($this->user)('single');
    $firstOfMany = ($this->user)('first-of-many');
    $secondOfMany = ($this->user)('second-of-many');

    $this->directory->withActiveUsers([$single, $firstOfMany, $secondOfMany]);

    $record = ($this->record)([
        'data' => [
            'reviewer' => (string) $single->getKey(),
            'reviewers' => [(string) $firstOfMany->getKey(), (string) $secondOfMany->getKey()],
        ],
    ]);

    $resolver = ($this->resolver)();

    $fromScalar = $resolver->resolve($record, ($this->circle)([
        'sources' => [CandidateSource::Field],
        'fieldKey' => 'reviewer',
    ]), $this->at);

    $fromList = $resolver->resolve($record, ($this->circle)([
        'sources' => [CandidateSource::Field],
        'fieldKey' => 'reviewers',
    ]), $this->at);

    expect(($this->ids)($fromScalar))->toBe([(string) $single->getKey()])
        ->and(($this->ids)($fromList))->toBe([
            (string) $firstOfMany->getKey(),
            (string) $secondOfMany->getKey(),
        ]);
});

it('collects nothing for a missing field key and nothing for the field source without one', function (): void {
    $owner = ($this->user)('owner');
    $this->directory->withActiveUsers([$owner]);

    $record = ($this->record)([
        'owner_id' => (string) $owner->getKey(),
        'data' => ['reviewer' => (string) $owner->getKey()],
    ]);

    $resolver = ($this->resolver)();

    $missingKey = $resolver->resolve($record, ($this->circle)([
        'sources' => [CandidateSource::Field],
        'fieldKey' => 'does_not_exist',
    ]), $this->at);

    $withoutKey = $resolver->resolve($record, ($this->circle)([
        'sources' => [CandidateSource::Field],
        'fieldKey' => null,
    ]), $this->at);

    expect(($this->ids)($missingKey))->toBe([])
        ->and(($this->ids)($withoutKey))->toBe([])
        ->and($this->directory->lastRequestedUserIds())->toBe([]);
});

it('hands free text of a data field on as an identifier that resolves to nobody', function (): void {
    $record = ($this->record)(['data' => ['titel' => 'Freitext ohne Benutzerbezug']]);

    $resolved = ($this->resolver)()->resolve($record, ($this->circle)([
        'sources' => [CandidateSource::Field],
        'fieldKey' => 'titel',
    ]), $this->at);

    expect($this->directory->lastRequestedUserIds())->toBe(['Freitext ohne Benutzerbezug'])
        ->and(($this->ids)($resolved))->toBe([]);
});

it('never takes the fixed list while its source is switched off', function (): void {
    ($this->resolver)()->resolve(($this->record)(), ($this->circle)([
        'sources' => [CandidateSource::Role],
        'roleIds' => [$this->roleId],
        'userIds' => [ModelStub::ulid('listed')],
    ]), $this->at);

    expect($this->directory->lastRequestedUserIds())->toBe([]);
});

it('unions the sources into one list without a repetition and without an empty identifier', function (): void {
    $reachableTwice = ($this->user)('reachable-twice');
    $onlyByRole = ($this->user)('only-by-role');

    $this->directory->withRoleHolders($this->roleId, [
        (string) $reachableTwice->getKey(),
        (string) $onlyByRole->getKey(),
    ]);

    ($this->resolver)()->resolve(($this->record)(), ($this->circle)([
        'sources' => [CandidateSource::Role, CandidateSource::FixedList],
        'roleIds' => [$this->roleId],
        'userIds' => ['', (string) $reachableTwice->getKey()],
    ]), $this->at);

    expect($this->directory->lastRequestedUserIds())->toBe([
        (string) $reachableTwice->getKey(),
        (string) $onlyByRole->getKey(),
    ]);
});

it('drops a candidate who is absent at the given instant', function (): void {
    $present = ($this->user)('present');
    $absent = ($this->user)('absent');
    $delegate = ($this->user)('delegate');

    $this->directory->withActiveUsers([$present, $absent]);

    StaticAbsenceDelegationDirectory::covering([
        ['user_id' => (string) $absent->getKey(), 'delegate_id' => (string) $delegate->getKey()],
    ]);

    $resolved = ($this->resolver)()->resolve(($this->record)(), ($this->circle)([
        'sources' => [CandidateSource::FixedList],
        'userIds' => [(string) $present->getKey(), (string) $absent->getKey()],
    ]), $this->at);

    expect(($this->ids)($resolved))->toBe([(string) $present->getKey()]);
});

it('keeps an absent candidate when absence must not be excluded', function (): void {
    $present = ($this->user)('present');
    $absent = ($this->user)('absent');
    $delegate = ($this->user)('delegate');

    $this->directory->withActiveUsers([$present, $absent]);

    $absences = StaticAbsenceDelegationDirectory::covering([
        ['user_id' => (string) $absent->getKey(), 'delegate_id' => (string) $delegate->getKey()],
    ]);

    $resolved = ($this->resolver)()->resolve(($this->record)(), ($this->circle)([
        'sources' => [CandidateSource::FixedList],
        'userIds' => [(string) $present->getKey(), (string) $absent->getKey()],
    ]), $this->at, false);

    expect(($this->ids)($resolved))->toBe([
        (string) $present->getKey(),
        (string) $absent->getKey(),
    ])
        ->and($absences->lookups)->toBe([]);
});

it('asks for absences with the instant it was handed', function (): void {
    $candidate = ($this->user)('candidate');
    $this->directory->withActiveUsers([$candidate]);

    ($this->resolver)()->resolve(($this->record)(), ($this->circle)([
        'sources' => [CandidateSource::FixedList],
        'userIds' => [(string) $candidate->getKey()],
    ]), CarbonImmutable::parse('2026-09-15T11:00:00+02:00'));

    expect($this->absences->lookups)->toHaveCount(1)
        ->and($this->absences->lookups[0]['subject'])->toBe([(string) $candidate->getKey()])
        ->and($this->absences->moments()[0]->format('Y-m-d H:i:s T'))->toBe('2026-09-15 09:00:00 UTC');
});

it('returns an empty collection and asks for nothing when no source is enabled', function (): void {
    $resolved = ($this->resolver)()->resolve(($this->record)([
        'team_id' => $this->recordTeamId,
        'owner_id' => ModelStub::ulid('owner'),
    ]), ($this->circle)(), $this->at);

    expect($resolved)->toBeInstanceOf(Collection::class)
        ->and(($this->ids)($resolved))->toBe([])
        ->and($this->directory->roleLookups)->toBe([])
        ->and($this->directory->teamLookups[0]['teamIds'])->toBe([])
        ->and($this->directory->lastRequestedUserIds())->toBe([])
        ->and($this->absences->lookups)->toBe([]);
});

it('never reads a record bound source on the record free path', function (): void {
    ($this->resolver)()->resolveWithoutRecord(($this->circle)([
        'sources' => [CandidateSource::Team, CandidateSource::Field],
        'teamIds' => [$this->namedTeamId],
        'includeRecordTeam' => true,
        'fieldKey' => 'owner_id',
    ]), $this->tenantId, $this->at);

    expect($this->directory->teamLookups[0]['teamIds'])->toBe([$this->namedTeamId])
        ->and($this->directory->lastRequestedUserIds())->toBe([]);
});

it('refuses to count a circle that depends on a record and reads nothing for it', function (): void {
    $counted = ($this->resolver)()->countWithoutRecord(($this->circle)([
        'sources' => [CandidateSource::Team],
        'includeRecordTeam' => true,
    ]), $this->tenantId);

    expect($counted)->toBeNull()
        ->and($this->directory->teamLookups)->toBe([])
        ->and($this->directory->userLookups)->toBe([]);
});

it('counts the record free circle without excluding the absent', function (): void {
    $present = ($this->user)('present');
    $absent = ($this->user)('absent');
    $delegate = ($this->user)('delegate');

    $this->directory->withActiveUsers([$present, $absent]);

    $absences = StaticAbsenceDelegationDirectory::covering([
        ['user_id' => (string) $absent->getKey(), 'delegate_id' => (string) $delegate->getKey()],
    ]);

    $counted = ($this->resolver)()->countWithoutRecord(($this->circle)([
        'sources' => [CandidateSource::FixedList],
        'userIds' => [(string) $present->getKey(), (string) $absent->getKey()],
    ]), $this->tenantId);

    expect($counted)->toBe(2)
        ->and($absences->lookups)->toBe([]);
});

it('maps the resolved users to their identifiers and picks the path by the record', function (): void {
    $candidate = ($this->user)('candidate');
    $this->directory->withActiveUsers([$candidate]);

    $circle = ($this->circle)([
        'sources' => [CandidateSource::Team],
        'teamIds' => [$this->namedTeamId],
        'includeRecordTeam' => true,
    ]);

    $resolver = ($this->resolver)();

    $withoutRecord = $resolver->resolveUserIds(null, $circle, $this->tenantId, $this->at);

    $withRecord = $resolver->resolveUserIds(
        ($this->record)(['team_id' => $this->recordTeamId]),
        $circle,
        $this->tenantId,
        $this->at,
    );

    expect($withoutRecord)->toBe([])
        ->and($withRecord)->toBe([])
        ->and($this->directory->teamLookups[0]['teamIds'])->toBe([$this->namedTeamId])
        ->and($this->directory->teamLookups[1]['teamIds'])->toBe([$this->namedTeamId, $this->recordTeamId]);
});

it('reports whether a circle depends on a record', function (): void {
    $resolver = ($this->resolver)();

    expect($resolver->dependsOnRecord(($this->circle)([
        'sources' => [CandidateSource::Team],
        'includeRecordTeam' => true,
    ])))->toBeTrue()
        ->and($resolver->dependsOnRecord(($this->circle)([
            'sources' => [CandidateSource::Field],
            'fieldKey' => 'owner_id',
        ])))->toBeTrue()
        ->and($resolver->dependsOnRecord(($this->circle)([
            'sources' => [CandidateSource::FixedList],
            'userIds' => [ModelStub::ulid('listed')],
        ])))->toBeFalse();
});

it('drops an unknown source value without throwing', function (): void {
    $circle = CandidateCircle::fromArray([
        'sources' => ['role', 'telepathy'],
        'role_ids' => ['01JABCDEFGHJKMNPQRSTVWXYZ'],
        'team_ids' => [],
        'include_record_team' => false,
        'field_key' => null,
        'user_ids' => [],
    ]);

    expect($circle->sources)->toBe([CandidateSource::Role])
        ->and($circle->hasSource(CandidateSource::Role))->toBeTrue()
        ->and($circle->hasSource(CandidateSource::Team))->toBeFalse();
});

it('builds an empty circle from an empty array', function (): void {
    $circle = CandidateCircle::fromArray([]);

    expect($circle->sources)->toBe([])
        ->and($circle->roleIds)->toBe([])
        ->and($circle->teamIds)->toBe([])
        ->and($circle->includeRecordTeam)->toBeFalse()
        ->and($circle->fieldKey)->toBeNull()
        ->and($circle->userIds)->toBe([])
        ->and($circle->toArray())->toBe([
            'sources' => [],
            'role_ids' => [],
            'team_ids' => [],
            'include_record_team' => false,
            'field_key' => null,
            'user_ids' => [],
        ]);
});

it('normalises a malformed config without throwing', function (): void {
    $circle = CandidateCircle::fromArray([
        'sources' => 'role',
        'role_ids' => ['a', 'a', '', 42, ['x']],
        'team_ids' => 'not-a-list',
        'include_record_team' => false,
        'field_key' => '',
        'user_ids' => null,
    ]);

    expect($circle->sources)->toBe([])
        ->and($circle->roleIds)->toBe(['a', '42'])
        ->and($circle->teamIds)->toBe([])
        ->and($circle->fieldKey)->toBeNull()
        ->and($circle->userIds)->toBe([])
        ->and($circle->toArray())->toBe([
            'sources' => [],
            'role_ids' => ['a', '42'],
            'team_ids' => [],
            'include_record_team' => false,
            'field_key' => null,
            'user_ids' => [],
        ]);
});

it('drops duplicate sources and a non string field key', function (): void {
    $circle = CandidateCircle::fromArray([
        'sources' => ['role', 'role', 42, 'team'],
        'field_key' => 42,
    ]);

    expect($circle->sources)->toBe([CandidateSource::Role, CandidateSource::Team])
        ->and($circle->fieldKey)->toBeNull();
});

it('round trips through the array format', function (): void {
    $config = [
        'sources' => ['role', 'team', 'field', 'fixed_list'],
        'role_ids' => ['01JROLEAAAAAAAAAAAAAAAAAAA'],
        'team_ids' => ['01JTEAMAAAAAAAAAAAAAAAAAAA'],
        'include_record_team' => true,
        'field_key' => 'owner_id',
        'user_ids' => ['01JUSERAAAAAAAAAAAAAAAAAAA'],
    ];

    $circle = CandidateCircle::fromArray($config);
    $roundTripped = CandidateCircle::fromArray($circle->toArray());

    expect($circle->toArray())->toBe($config)
        ->and($roundTripped->toArray())->toBe($config)
        ->and($roundTripped->sources)->toBe([
            CandidateSource::Role,
            CandidateSource::Team,
            CandidateSource::Field,
            CandidateSource::FixedList,
        ]);
});
