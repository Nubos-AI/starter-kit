<?php

declare(strict_types=1);

use App\Contracts\ConfigBundle\ArtifactWriterInterface;
use App\DTOs\ConfigBundle\ArtifactWriteResult;
use App\DTOs\ConfigBundle\BundleArtifact;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\ConfigBundle\ArtifactWriteAction;
use App\Enums\Promotion\DiffState;
use App\Exceptions\ConfigBundle\MissingActingUserException;
use App\Exceptions\ConfigBundle\UnsupportedArtifactKindException;
use App\Models\User;
use App\Support\ConfigBundle\ArtifactWriterRegistry;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->actingUser = new User;
    $this->actingUser->email = 'operator@example.test';

    $this->artifact = function (string $key, ArtifactKind $kind = ArtifactKind::Roles): BundleArtifact {
        return new BundleArtifact($kind, $key, ['name' => $key]);
    };

    $this->singleWriter = function (ArtifactKind ...$kinds): ArtifactWriterInterface {
        return new class($kinds) implements ArtifactWriterInterface
        {
            /**
             * @param  list<ArtifactKind>  $kinds
             */
            public function __construct(private readonly array $kinds) {}

            public function supports(ArtifactKind $kind): bool
            {
                return in_array($kind, $this->kinds, true);
            }

            public function apply(User $actingUser, ArtifactKind $kind, BundleArtifact $artifact, DiffState $state): ArtifactWriteResult
            {
                if ($state === DiffState::Unchanged) {
                    return new ArtifactWriteResult($kind, $artifact->key, ArtifactWriteAction::Skipped, null);
                }

                return new ArtifactWriteResult(
                    $kind,
                    $artifact->key,
                    ArtifactWriteAction::Created,
                    "model-{$artifact->key}",
                    [$actingUser->email],
                );
            }

            public function remove(User $actingUser, ArtifactKind $kind, string $key): ArtifactWriteResult
            {
                return new ArtifactWriteResult($kind, $key, ArtifactWriteAction::Removed, null);
            }

            /**
             * @return list<ArtifactWriteResult>
             */
            public function flush(User $actingUser): array
            {
                return [];
            }
        };
    };

    $this->setWriter = function (ArtifactKind $supported = ArtifactKind::RolePermissions): ArtifactWriterInterface {
        return new class($supported) implements ArtifactWriterInterface
        {
            /**
             * @var array<string, array<string, string>>
             */
            private array $collected = [];

            public function __construct(private readonly ArtifactKind $supported) {}

            public function supports(ArtifactKind $kind): bool
            {
                return $kind === $this->supported;
            }

            public function apply(User $actingUser, ArtifactKind $kind, BundleArtifact $artifact, DiffState $state): ArtifactWriteResult
            {
                [$holder, $member] = explode('/', $artifact->key, 2);

                $this->collected[$holder][$member] = $member;

                return new ArtifactWriteResult($kind, $artifact->key, ArtifactWriteAction::Skipped, null, ['deferred to flush']);
            }

            public function remove(User $actingUser, ArtifactKind $kind, string $key): ArtifactWriteResult
            {
                [$holder, $member] = explode('/', $key, 2);

                unset($this->collected[$holder][$member]);

                return new ArtifactWriteResult($kind, $key, ArtifactWriteAction::Skipped, null, ['deferred to flush']);
            }

            /**
             * @return list<ArtifactWriteResult>
             */
            public function flush(User $actingUser): array
            {
                $results = [];

                foreach ($this->collected as $holder => $members) {
                    $results[] = new ArtifactWriteResult(
                        $this->supported,
                        $holder,
                        ArtifactWriteAction::Updated,
                        "model-{$holder}",
                        array_values($members),
                    );
                }

                $this->collected = [];

                return $results;
            }
        };
    };

    $this->failingSetWriter = function (ArtifactKind $supported = ArtifactKind::RolePermissions): ArtifactWriterInterface {
        return new class($supported) implements ArtifactWriterInterface
        {
            /**
             * @var array<string, array<string, string>>
             */
            private array $collected = [];

            public function __construct(private readonly ArtifactKind $supported) {}

            public function supports(ArtifactKind $kind): bool
            {
                return $kind === $this->supported;
            }

            public function apply(User $actingUser, ArtifactKind $kind, BundleArtifact $artifact, DiffState $state): ArtifactWriteResult
            {
                [$holder, $member] = explode('/', $artifact->key, 2);

                $this->collected[$holder][$member] = $member;

                return new ArtifactWriteResult($kind, $artifact->key, ArtifactWriteAction::Skipped, null, ['deferred to flush']);
            }

            public function remove(User $actingUser, ArtifactKind $kind, string $key): ArtifactWriteResult
            {
                [$holder, $member] = explode('/', $key, 2);

                unset($this->collected[$holder][$member]);

                return new ArtifactWriteResult($kind, $key, ArtifactWriteAction::Skipped, null, ['deferred to flush']);
            }

            /**
             * @return list<ArtifactWriteResult>
             */
            public function flush(User $actingUser): array
            {
                try {
                    if ($this->collected !== []) {
                        throw new RuntimeException('The target set could not be synchronised.');
                    }

                    return [];
                } finally {
                    $this->collected = [];
                }
            }
        };
    };
});

it('resolves the writer whose supports method answers true for the requested kind', function (): void {
    $unrelated = ($this->singleWriter)(ArtifactKind::Reports);
    $responsible = ($this->singleWriter)(ArtifactKind::Roles);

    $registry = new ArtifactWriterRegistry([$unrelated, $responsible]);

    expect($registry->for(ArtifactKind::Roles))->toBe($responsible);
});

it('hands the acting user to the writer so a write can name who performed it', function (): void {
    $writer = ($this->singleWriter)(ArtifactKind::Roles);

    $result = $writer->apply($this->actingUser, ArtifactKind::Roles, ($this->artifact)('sales-manager'), DiffState::Added);

    expect($result->action)->toBe(ArtifactWriteAction::Created)
        ->and($result->kind)->toBe(ArtifactKind::Roles)
        ->and($result->key)->toBe('sales-manager')
        ->and($result->modelId)->toBe('model-sales-manager')
        ->and($result->notes)->toBe(['operator@example.test']);
});

it('returns every injected writer from all in injection order as the same instances', function (): void {
    $first = ($this->singleWriter)(ArtifactKind::Roles);
    $second = ($this->singleWriter)(ArtifactKind::Reports);
    $third = ($this->singleWriter)(ArtifactKind::Dashboards);

    $registry = new ArtifactWriterRegistry([$first, $second, $third]);

    expect($registry->all())->toBe([$first, $second, $third]);
});

it('lets the first injected writer win when two writers support the same kind', function (): void {
    $first = ($this->singleWriter)(ArtifactKind::Roles);
    $second = ($this->singleWriter)(ArtifactKind::Roles);

    $registry = new ArtifactWriterRegistry([$first, $second]);

    expect($registry->for(ArtifactKind::Roles))->toBe($first)
        ->and($registry->for(ArtifactKind::Roles))->not->toBe($second);
});

it('materialises a generator of writers so a later lookup and every all call still see them', function (): void {
    $first = ($this->singleWriter)(ArtifactKind::Roles);
    $second = ($this->singleWriter)(ArtifactKind::Reports);

    $writers = (static function () use ($first, $second): Generator {
        yield $first;
        yield $second;
    })();

    $registry = new ArtifactWriterRegistry($writers);

    expect($registry->for(ArtifactKind::Reports))->toBe($second)
        ->and($registry->all())->toBe([$first, $second])
        ->and($registry->all())->toBe([$first, $second]);
});

it('constructs without any writer at all and then answers all with an empty list', function (): void {
    $registry = new ArtifactWriterRegistry;

    expect($registry->all())->toBe([])
        ->and(fn () => $registry->for(ArtifactKind::Roles))
        ->toThrow(UnsupportedArtifactKindException::class);
});

it('flushes an empty list for a writer that has collected no set state', function (): void {
    $writer = ($this->singleWriter)(ArtifactKind::Roles);

    $writer->apply($this->actingUser, ArtifactKind::Roles, ($this->artifact)('sales-manager'), DiffState::Added);

    expect($writer->flush($this->actingUser))->toBe([]);
});

it('reports a skipped write with a null model id and no notes by default', function (): void {
    $writer = ($this->singleWriter)(ArtifactKind::Roles);

    $result = $writer->apply($this->actingUser, ArtifactKind::Roles, ($this->artifact)('sales-manager'), DiffState::Unchanged);

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and($result->modelId)->toBeNull()
        ->and($result->notes)->toBe([])
        ->and($result->kind)->toBe(ArtifactKind::Roles)
        ->and($result->key)->toBe('sales-manager');
});

it('rejects a kind without a registered writer by throwing a typed exception instead of returning null', function (): void {
    $registry = new ArtifactWriterRegistry([($this->singleWriter)(ArtifactKind::Roles)]);

    try {
        $registry->for(ArtifactKind::Dashboards);
    } catch (UnsupportedArtifactKindException $exception) {
        expect($exception->kind)->toBe(ArtifactKind::Dashboards)
            ->and($exception->getMessage())->toContain(ArtifactKind::Dashboards->value);

        return;
    }

    $this->fail('Expected an UnsupportedArtifactKindException to be thrown.');
});

it('demands a non nullable acting user as the first argument of every writing method', function (string $method): void {
    $parameters = (new ReflectionMethod(ArtifactWriterInterface::class, $method))->getParameters();
    $first = $parameters[0];
    $type = $first->getType();

    expect($first->getName())->toBe('actingUser')
        ->and($type)->toBeInstanceOf(ReflectionNamedType::class)
        ->and($type->getName())->toBe(User::class)
        ->and($type->allowsNull())->toBeFalse()
        ->and($first->isOptional())->toBeFalse()
        ->and($first->isDefaultValueAvailable())->toBeFalse();
})->with(['apply', 'remove', 'flush']);

it('exposes exactly supports apply remove and flush so no writing method can grow without an acting user', function (): void {
    $methodNames = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(ArtifactWriterInterface::class))->getMethods(),
    );

    sort($methodNames);

    expect($methodNames)->toBe(['apply', 'flush', 'remove', 'supports']);
});

it('collapses two applies for the same holder into a single flush result', function (): void {
    $writer = ($this->setWriter)();

    $writer->apply($this->actingUser, ArtifactKind::RolePermissions, ($this->artifact)('sales-manager/records.view', ArtifactKind::RolePermissions), DiffState::Added);
    $writer->apply($this->actingUser, ArtifactKind::RolePermissions, ($this->artifact)('sales-manager/records.update', ArtifactKind::RolePermissions), DiffState::Added);

    $results = $writer->flush($this->actingUser);

    expect($results)->toHaveCount(1)
        ->and($results[0]->kind)->toBe(ArtifactKind::RolePermissions)
        ->and($results[0]->key)->toBe('sales-manager')
        ->and($results[0]->action)->toBe(ArtifactWriteAction::Updated)
        ->and($results[0]->modelId)->toBe('model-sales-manager')
        ->and($results[0]->notes)->toBe(['records.view', 'records.update']);
});

it('returns an empty list from a second flush that follows no further apply', function (): void {
    $writer = ($this->setWriter)();

    $writer->apply($this->actingUser, ArtifactKind::RolePermissions, ($this->artifact)('sales-manager/records.view', ArtifactKind::RolePermissions), DiffState::Added);

    expect($writer->flush($this->actingUser))->toHaveCount(1)
        ->and($writer->flush($this->actingUser))->toBe([]);
});

it('drops a removed member from the collected set so flush syncs the holder without it', function (): void {
    $writer = ($this->setWriter)();

    $writer->apply($this->actingUser, ArtifactKind::RolePermissions, ($this->artifact)('sales-manager/records.view', ArtifactKind::RolePermissions), DiffState::Added);
    $writer->apply($this->actingUser, ArtifactKind::RolePermissions, ($this->artifact)('sales-manager/records.delete', ArtifactKind::RolePermissions), DiffState::Added);
    $writer->remove($this->actingUser, ArtifactKind::RolePermissions, 'sales-manager/records.delete');

    $results = $writer->flush($this->actingUser);

    expect($results)->toHaveCount(1)
        ->and($results[0]->key)->toBe('sales-manager')
        ->and($results[0]->notes)->toBe(['records.view']);
});

it('leaves a set writer empty even when flush throws, so a retry cannot resync a stale target set', function (): void {
    $writer = ($this->failingSetWriter)();

    $writer->apply($this->actingUser, ArtifactKind::RolePermissions, ($this->artifact)('sales-manager/records.view', ArtifactKind::RolePermissions), DiffState::Added);

    expect(fn () => $writer->flush($this->actingUser))->toThrow(RuntimeException::class);

    expect($writer->flush($this->actingUser))->toBe([]);
});

it('carries the caller supplied message and reason so a console path can name why no acting user was resolved', function (): void {
    $exception = new MissingActingUserException('Bitte geben Sie --as-user= an.', 'no-as-user-flag');

    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->getMessage())->toBe('Bitte geben Sie --as-user= an.')
        ->and($exception->reason)->toBe('no-as-user-flag');
});
