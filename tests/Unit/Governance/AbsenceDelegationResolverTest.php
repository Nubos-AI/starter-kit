<?php

declare(strict_types=1);

use App\Support\Governance\AbsenceDelegationResolver;
use Carbon\CarbonImmutable;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticAbsenceDelegationDirectory;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->first = ModelStub::ulid('first-user');
    $this->second = ModelStub::ulid('second-user');
    $this->third = ModelStub::ulid('third-user');

    $this->inside = CarbonImmutable::parse('2026-09-03 12:00:00', 'UTC');

    /** @var callable(list<array{user_id: string, delegate_id: string}>):AbsenceDelegationResolver */
    $this->resolverCovering = function (array $rows): AbsenceDelegationResolver {
        $this->directory = StaticAbsenceDelegationDirectory::covering($rows);

        return app(AbsenceDelegationResolver::class);
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('hands out the direct delegate and never walks the chain a second hop', function (): void {
    $resolver = ($this->resolverCovering)([
        ['user_id' => $this->first, 'delegate_id' => $this->second],
        ['user_id' => $this->second, 'delegate_id' => $this->third],
    ]);

    expect($resolver->delegateFor($this->first, $this->inside))->toBe($this->second)
        ->and($this->directory->lookups)->toHaveCount(1)
        ->and($resolver->delegateFor($this->second, $this->inside))->toBe($this->third)
        ->and($this->directory->lookups)->toHaveCount(2);
});

it('stops a delegation cycle after the single hop it is allowed to take', function (): void {
    $resolver = ($this->resolverCovering)([
        ['user_id' => $this->first, 'delegate_id' => $this->second],
        ['user_id' => $this->second, 'delegate_id' => $this->first],
    ]);

    expect($resolver->delegateFor($this->first, $this->inside))->toBe($this->second)
        ->and($resolver->delegateFor($this->second, $this->inside))->toBe($this->first)
        ->and($this->directory->lookups)->toHaveCount(2);
});

it('reports nobody as delegate when no period covers the instant', function (): void {
    $resolver = ($this->resolverCovering)([]);

    expect($resolver->delegateFor($this->first, $this->inside))->toBeNull()
        ->and($resolver->delegatorsFor($this->first, $this->inside))->toBe([])
        ->and($resolver->absentUserIds([$this->first], $this->inside))->toBe([]);
});

it('shifts an offset bearing instant to utc before it asks for a covering period', function (): void {
    $resolver = ($this->resolverCovering)([
        ['user_id' => $this->first, 'delegate_id' => $this->second],
    ]);

    $resolver->delegateFor($this->first, CarbonImmutable::parse('2026-09-01T09:00:00+02:00'));
    $resolver->absentUserIds([$this->first], CarbonImmutable::parse('2026-09-01T09:00:00+02:00'));
    $resolver->delegatorsFor($this->second, CarbonImmutable::parse('2026-09-01T09:00:00+02:00'));

    $formatted = array_map(
        static fn (CarbonImmutable $moment): string => $moment->format('Y-m-d H:i:s T'),
        $this->directory->moments(),
    );

    expect($formatted)->toBe([
        '2026-09-01 07:00:00 UTC',
        '2026-09-01 07:00:00 UTC',
        '2026-09-01 07:00:00 UTC',
    ]);
});

it('asks nothing at all for an empty candidate list', function (): void {
    $resolver = ($this->resolverCovering)([
        ['user_id' => $this->first, 'delegate_id' => $this->second],
    ]);

    expect($resolver->absentUserIds([], $this->inside))->toBe([])
        ->and($this->directory->lookups)->toBe([]);
});

it('names an absent user once although several periods cover the instant', function (): void {
    $resolver = ($this->resolverCovering)([
        ['user_id' => $this->first, 'delegate_id' => $this->second],
        ['user_id' => $this->first, 'delegate_id' => $this->third],
    ]);

    expect($resolver->absentUserIds([$this->first], $this->inside))->toBe([$this->first]);
});

it('reports only the candidates it was handed as absent', function (): void {
    $resolver = ($this->resolverCovering)([
        ['user_id' => $this->first, 'delegate_id' => $this->third],
        ['user_id' => $this->second, 'delegate_id' => $this->third],
    ]);

    expect($resolver->absentUserIds([$this->first], $this->inside))->toBe([$this->first])
        ->and($this->directory->lookups[0]['subject'])->toBe([$this->first]);
});

it('lists every delegator of a delegate without repeating one', function (): void {
    $resolver = ($this->resolverCovering)([
        ['user_id' => $this->first, 'delegate_id' => $this->third],
        ['user_id' => $this->second, 'delegate_id' => $this->third],
        ['user_id' => $this->first, 'delegate_id' => $this->third],
    ]);

    expect($resolver->delegatorsFor($this->third, $this->inside))->toBe([$this->first, $this->second]);
});

it('returns a list without gaps after it has dropped the repetitions', function (): void {
    $resolver = ($this->resolverCovering)([
        ['user_id' => $this->first, 'delegate_id' => $this->third],
        ['user_id' => $this->first, 'delegate_id' => $this->third],
        ['user_id' => $this->second, 'delegate_id' => $this->third],
    ]);

    $delegators = $resolver->delegatorsFor($this->third, $this->inside);

    expect(array_keys($delegators))->toBe([0, 1]);
});
