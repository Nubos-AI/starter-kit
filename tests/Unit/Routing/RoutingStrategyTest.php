<?php

declare(strict_types=1);

use App\DTOs\Governance\CandidateCircle;
use App\DTOs\Routing\RoutingContext;
use App\Enums\Governance\CandidateSource;
use App\Enums\Routing\RoutingStrategy;
use App\Exceptions\Routing\UnknownRoutingStrategyException;
use App\Handlers\Routing\LoadStrategyHandler;
use App\Handlers\Routing\RandomStrategyHandler;
use App\Handlers\Routing\RoundRobinStrategyHandler;
use App\Handlers\Routing\SkillStrategyHandler;
use App\Models\CustomRecord;
use App\Models\Skill;
use App\Models\User;
use App\Support\Governance\AssignmentLoadCounter;
use App\Support\Governance\CandidateCircleResolver;
use App\Support\Routing\RoutingCandidateResolver;
use App\Support\Routing\RoutingStrategyRegistry;
use App\Support\Routing\SkillFilter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    /** @var callable(array<string, mixed>):CustomRecord */
    $this->record = fn (array $attributes = []): CustomRecord => ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('routing-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => ModelStub::ulid('routing-object-type'),
        'team_id' => null,
        'data' => [],
        ...$attributes,
    ]);

    /** @var callable(string):User */
    $this->user = fn (string $seed): User => ModelStub::make(User::class, [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => $this->tenant->getKey(),
    ]);

    /** @var callable(list<User>):Collection<int, User> */
    $this->pool = static fn (array $users): Collection => new Collection($users);

    /** @var callable(array<string, mixed>):RoutingContext */
    $this->context = fn (array $overrides = []): RoutingContext => new RoutingContext(
        tenantId: $overrides['tenantId'] ?? (string) $this->tenant->getKey(),
        automationId: ModelStub::ulid('routing-automation'),
        nodeId: $overrides['nodeId'] ?? 'assign-node',
        strategy: $overrides['strategy'] ?? RoutingStrategy::Random,
        circle: new CandidateCircle(
            sources: [CandidateSource::FixedList],
            roleIds: [],
            teamIds: [],
            includeRecordTeam: false,
            fieldKey: null,
            userIds: $overrides['userIds'] ?? [],
        ),
        skillId: $overrides['skillId'] ?? null,
        skillFieldKey: $overrides['skillFieldKey'] ?? null,
        at: CarbonImmutable::parse('2026-09-15T09:00:00+00:00'),
        restrictToUserIds: $overrides['restrictToUserIds'] ?? null,
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('resolves a handler for every routing strategy', function (): void {
    $registry = app(RoutingStrategyRegistry::class);

    foreach (RoutingStrategy::cases() as $strategy) {
        expect($registry->hasHandler($strategy))->toBeTrue()
            ->and($registry->handlerFor($strategy)->strategy())->toBe($strategy);
    }
});

it('lets a missing handler fail loudly instead of looking like no candidate', function (): void {
    config()->set('routing.strategy_handlers', []);

    $registry = app(RoutingStrategyRegistry::class);

    expect($registry->hasHandler(RoutingStrategy::Load))->toBeFalse()
        ->and(fn () => $registry->handlerFor(RoutingStrategy::Load))
        ->toThrow(UnknownRoutingStrategyException::class, 'load');
});

it('gives every routing strategy a distinct label', function (): void {
    $labels = array_map(
        static fn (RoutingStrategy $strategy): string => $strategy->label(),
        RoutingStrategy::cases(),
    );

    expect(array_unique($labels))->toHaveCount(count(RoutingStrategy::cases()));
});

it('picks a candidate out of the circle at random and nobody out of an empty one', function (): void {
    $handler = new RandomStrategyHandler;
    $circle = ($this->pool)([($this->user)('random-a'), ($this->user)('random-b')]);

    $picked = [];

    foreach (range(1, 40) as $ignored) {
        $picked[] = (string) $handler->pick($circle, ($this->context)())?->getKey();
    }

    expect(array_unique($picked))->not->toBeEmpty()
        ->and(array_diff(array_unique($picked), $circle->map(static fn (User $user): string => (string) $user->getKey())->all()))->toBe([])
        ->and($handler->pick(($this->pool)([]), ($this->context)()))->toBeNull();
});

it('picks the least loaded candidate and breaks a tie by the smallest id', function (): void {
    $busy = ($this->user)('load-busy');
    $idle = ($this->user)('load-idle');

    $counter = Mockery::mock(AssignmentLoadCounter::class);
    $counter->shouldReceive('countFor')->andReturn([
        (string) $busy->getKey() => 4,
        (string) $idle->getKey() => 1,
    ]);

    $handler = new LoadStrategyHandler($counter);

    expect((string) $handler->pick(($this->pool)([$busy, $idle]), ($this->context)())?->getKey())
        ->toBe((string) $idle->getKey());

    $tied = Mockery::mock(AssignmentLoadCounter::class);
    $tied->shouldReceive('countFor')->andReturn([]);

    $ordered = [($this->user)('tie-a'), ($this->user)('tie-b')];
    usort($ordered, static fn (User $left, User $right): int => strcmp((string) $left->getKey(), (string) $right->getKey()));

    expect((string) (new LoadStrategyHandler($tied))->pick(($this->pool)(array_reverse($ordered)), ($this->context)())?->getKey())
        ->toBe((string) $ordered[0]->getKey());
});

it('asks the load counter only for the candidate ids of the context tenant', function (): void {
    $candidate = ($this->user)('load-asked');

    $counter = Mockery::mock(AssignmentLoadCounter::class);
    $counter->shouldReceive('countFor')
        ->once()
        ->with([(string) $candidate->getKey()], (string) $this->tenant->getKey())
        ->andReturn([]);

    (new LoadStrategyHandler($counter))->pick(($this->pool)([$candidate]), ($this->context)());
});

it('leaves an empty circle to the load counter untouched', function (): void {
    $counter = Mockery::mock(AssignmentLoadCounter::class);
    $counter->shouldNotReceive('countFor');

    expect((new LoadStrategyHandler($counter))->pick(($this->pool)([]), ($this->context)()))->toBeNull();
});

it('hands the skill strategy over to the random strategy instead of ordering by itself', function (): void {
    $only = ($this->user)('skill-only');
    $handler = new SkillStrategyHandler(new RandomStrategyHandler);

    expect($handler->strategy())->toBe(RoutingStrategy::Skill)
        ->and((string) $handler->pick(($this->pool)([$only]), ($this->context)())?->getKey())
        ->toBe((string) $only->getKey())
        ->and($handler->pick(($this->pool)([]), ($this->context)()))->toBeNull();
});

it('refuses a selection whose context tenant does not match the record', function (): void {
    $circleResolver = Mockery::mock(CandidateCircleResolver::class);
    $circleResolver->shouldNotReceive('resolve');

    $skillFilter = Mockery::mock(SkillFilter::class);
    $skillFilter->shouldNotReceive('resolveSkillId');

    $resolver = new RoutingCandidateResolver($circleResolver, app(RoutingStrategyRegistry::class), $skillFilter);

    expect($resolver->select(($this->record)(), ($this->context)(['tenantId' => ModelStub::ulid('foreign')])))->toBeNull()
        ->and($resolver->select(($this->record)(), ($this->context)(['tenantId' => ''])))->toBeNull();
});

it('returns nobody when the required skill cannot be resolved at all', function (): void {
    $candidate = ($this->user)('skill-required');

    $circleResolver = Mockery::mock(CandidateCircleResolver::class);
    $circleResolver->shouldReceive('resolve')->andReturn(($this->pool)([$candidate]));

    $skillFilter = Mockery::mock(SkillFilter::class);
    $skillFilter->shouldReceive('resolveSkillId')->andReturnNull();
    $skillFilter->shouldNotReceive('apply');

    $resolver = new RoutingCandidateResolver($circleResolver, app(RoutingStrategyRegistry::class), $skillFilter);

    expect($resolver->select(($this->record)(), ($this->context)(['skillId' => ModelStub::ulid('skill')])))->toBeNull()
        ->and($resolver->select(($this->record)(), ($this->context)(['skillFieldKey' => 'required_skill'])))->toBeNull();
});

it('narrows the circle to the restricted user ids after the skill filter has run', function (): void {
    $allowed = ($this->user)('restrict-allowed');
    $refused = ($this->user)('restrict-refused');

    $circleResolver = Mockery::mock(CandidateCircleResolver::class);
    $circleResolver->shouldReceive('resolve')->andReturn(($this->pool)([$allowed, $refused]));

    $skillFilter = Mockery::mock(SkillFilter::class);
    $skillFilter->shouldReceive('resolveSkillId')->andReturn(ModelStub::ulid('skill'));
    $skillFilter->shouldReceive('apply')
        ->once()
        ->andReturn(($this->pool)([$allowed, $refused]));

    $resolver = new RoutingCandidateResolver($circleResolver, app(RoutingStrategyRegistry::class), $skillFilter);

    $picked = $resolver->select(($this->record)(), ($this->context)([
        'skillId' => ModelStub::ulid('skill'),
        'restrictToUserIds' => [(string) $allowed->getKey()],
    ]));

    expect((string) $picked?->getKey())->toBe((string) $allowed->getKey());
});

it('returns nobody when the restriction leaves the circle empty and never asks a strategy handler', function (): void {
    $candidate = ($this->user)('restrict-empty');

    $circleResolver = Mockery::mock(CandidateCircleResolver::class);
    $circleResolver->shouldReceive('resolve')->andReturn(($this->pool)([$candidate]));

    $skillFilter = Mockery::mock(SkillFilter::class);
    $skillFilter->shouldReceive('resolveSkillId')->andReturnNull();
    $skillFilter->shouldReceive('apply')->andReturn(($this->pool)([$candidate]));

    $registry = Mockery::mock(RoutingStrategyRegistry::class);
    $registry->shouldNotReceive('handlerFor');

    $resolver = new RoutingCandidateResolver($circleResolver, $registry, $skillFilter);

    expect($resolver->select(($this->record)(), ($this->context)(['restrictToUserIds' => []])))->toBeNull();
});

it('returns nobody for an empty circle without asking a strategy handler', function (): void {
    $circleResolver = Mockery::mock(CandidateCircleResolver::class);
    $circleResolver->shouldReceive('resolve')->andReturn(($this->pool)([]));

    $skillFilter = Mockery::mock(SkillFilter::class);
    $skillFilter->shouldReceive('resolveSkillId')->andReturnNull();
    $skillFilter->shouldReceive('apply')->andReturn(($this->pool)([]));

    $registry = Mockery::mock(RoutingStrategyRegistry::class);
    $registry->shouldNotReceive('handlerFor');

    $resolver = new RoutingCandidateResolver($circleResolver, $registry, $skillFilter);

    expect($resolver->select(($this->record)(), ($this->context)()))->toBeNull();
});

it('lets an unregistered strategy surface as an exception instead of an empty selection', function (): void {
    config()->set('routing.strategy_handlers', []);

    $candidate = ($this->user)('strategy-missing');

    $circleResolver = Mockery::mock(CandidateCircleResolver::class);
    $circleResolver->shouldReceive('resolve')->andReturn(($this->pool)([$candidate]));

    $skillFilter = Mockery::mock(SkillFilter::class);
    $skillFilter->shouldReceive('resolveSkillId')->andReturnNull();
    $skillFilter->shouldReceive('apply')->andReturn(($this->pool)([$candidate]));

    $resolver = new RoutingCandidateResolver($circleResolver, app(RoutingStrategyRegistry::class), $skillFilter);

    expect(fn () => $resolver->select(($this->record)(), ($this->context)()))
        ->toThrow(UnknownRoutingStrategyException::class);
});

it('names no skill at all when neither a skill id nor a skill field is configured', function (): void {
    expect((new SkillFilter)->resolveSkillId(($this->record)(), ($this->context)()))->toBeNull();
});

it('names no skill when the configured record field holds no usable name', function (): void {
    $filter = new SkillFilter;

    foreach ([[], ['required_skill' => ''], ['required_skill' => 17], ['other' => 'accounting']] as $data) {
        expect($filter->resolveSkillId(
            ($this->record)(['data' => $data]),
            ($this->context)(['skillFieldKey' => 'required_skill']),
        ))->toBeNull();
    }
});

it('reads a skill of the context tenant alone and never through the ambient tenant scope', function (): void {
    $foreignTenantId = ModelStub::ulid('skill-foreign-tenant');
    $skillId = ModelStub::ulid('skill-by-id');

    $shape = QueryShape::attemptedBy(fn () => (new SkillFilter)->resolveSkillId(
        ($this->record)(),
        ($this->context)(['tenantId' => $foreignTenantId, 'skillId' => $skillId]),
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('skills'))->toBeTrue()
        ->and($shape->sql)->toContain('"tenant_id" = ?')
        ->and($shape->sql)->toContain('"id" = ?')
        ->and($shape->bindings)->toBe([$foreignTenantId, $skillId])
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeFalse();
});

it('looks a skill named in a record field up by name inside the context tenant', function (): void {
    $shape = QueryShape::attemptedBy(fn () => (new SkillFilter)->resolveSkillId(
        ($this->record)(['data' => ['required_skill' => 'accounting']]),
        ($this->context)(['skillFieldKey' => 'required_skill']),
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('skills'))->toBeTrue()
        ->and($shape->sql)->toContain('"name" = ?')
        ->and($shape->bindings)->toBe([(string) $this->tenant->getKey(), 'accounting'])
        ->and($shape->hidesSoftDeleted('skills'))->toBeTrue();
});

it('never derives a skill from a role or a team membership', function (): void {
    $shape = QueryShape::attemptedBy(fn () => (new SkillFilter)->resolveSkillId(
        ($this->record)(['data' => ['required_skill' => 'accounting']]),
        ($this->context)(['skillFieldKey' => 'required_skill']),
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->not->toContain('roles')
        ->and($shape->sql)->not->toContain('teams')
        ->and(Skill::query()->getModel()->getTable())->toBe('skills');
});

it('leaves the circle untouched when no skill narrows it', function (): void {
    $circle = ($this->pool)([($this->user)('apply-a'), ($this->user)('apply-b')]);

    expect((new SkillFilter)->apply($circle, null))->toBe($circle);
});

it('asks the skill holder table before it narrows a circle by skill', function (): void {
    $skillId = ModelStub::ulid('skill-holders');

    $shape = QueryShape::attemptedBy(fn () => (new SkillFilter)->apply(
        ($this->pool)([($this->user)('holder')]),
        $skillId,
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('skill_user'))->toBeTrue()
        ->and($shape->hasBinding($skillId))->toBeTrue();
});

it('walks the candidates in a stable ascending order and starts over after the last one', function (): void {
    $handler = new RoundRobinStrategyHandler;

    $ordered = [($this->user)('rr-1'), ($this->user)('rr-2'), ($this->user)('rr-3')];
    usort($ordered, static fn (User $left, User $right): int => strcmp((string) $left->getKey(), (string) $right->getKey()));

    $pool = ($this->pool)($ordered);

    expect((string) $handler->nextCandidate($pool, null)->getKey())->toBe((string) $ordered[0]->getKey())
        ->and((string) $handler->nextCandidate($pool, (string) $ordered[0]->getKey())->getKey())->toBe((string) $ordered[1]->getKey())
        ->and((string) $handler->nextCandidate($pool, (string) $ordered[1]->getKey())->getKey())->toBe((string) $ordered[2]->getKey())
        ->and((string) $handler->nextCandidate($pool, (string) $ordered[2]->getKey())->getKey())->toBe((string) $ordered[0]->getKey());
});

it('repeats the very same choice for an unchanged cursor state', function (): void {
    $handler = new RoundRobinStrategyHandler;

    $ordered = [($this->user)('repeat-1'), ($this->user)('repeat-2')];
    usort($ordered, static fn (User $left, User $right): int => strcmp((string) $left->getKey(), (string) $right->getKey()));

    $pool = ($this->pool)($ordered);
    $lastUserId = (string) $ordered[0]->getKey();

    expect((string) $handler->nextCandidate($pool, $lastUserId)->getKey())
        ->toBe((string) $handler->nextCandidate($pool, $lastUserId)->getKey());
});

it('advances to the next existing candidate when the remembered one left the circle', function (): void {
    $handler = new RoundRobinStrategyHandler;

    $ordered = [($this->user)('left-1'), ($this->user)('left-2'), ($this->user)('left-3')];
    usort($ordered, static fn (User $left, User $right): int => strcmp((string) $left->getKey(), (string) $right->getKey()));

    $remaining = ($this->pool)([$ordered[0], $ordered[2]]);

    expect((string) $handler->nextCandidate($remaining, (string) $ordered[1]->getKey())->getKey())
        ->toBe((string) $ordered[2]->getKey());
});

it('picks the only candidate again and again', function (): void {
    $handler = new RoundRobinStrategyHandler;
    $only = ($this->user)('single');
    $pool = ($this->pool)([$only]);

    expect((string) $handler->nextCandidate($pool, null)->getKey())->toBe((string) $only->getKey())
        ->and((string) $handler->nextCandidate($pool, (string) $only->getKey())->getKey())->toBe((string) $only->getKey());
});
