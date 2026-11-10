<?php

declare(strict_types=1);

use App\Actions\Goals\BulkDeleteGoalsAction;
use App\Actions\Goals\DeleteGoalAction;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('bulk-goal-tenant');
    $this->actor = AccessContext::actAs(AccessContext::user($this->tenant, [], 'bulk-goal-actor'));

    $this->governedGoalId = ModelStub::ulid('bulk-goal-governed');
    $this->foreignGoalId = ModelStub::ulid('bulk-goal-foreign');

    $this->connection = StaticQueryConnection::install(fn (string $sql): array => str_contains($sql, 'from "goals"')
        ? [
            ['id' => $this->governedGoalId, 'tenant_id' => (string) $this->tenant->getKey()],
            ['id' => $this->foreignGoalId, 'tenant_id' => (string) $this->tenant->getKey()],
        ]
        : []);

    $this->askedGoalIds = new ArrayObject;
    $this->allowedGoalIds = new ArrayObject;

    $asked = $this->askedGoalIds;
    $allowed = $this->allowedGoalIds;
    Gate::before(static function (?Authenticatable $user, string $ability, array $arguments) use ($asked, $allowed): ?bool {
        if ($ability !== 'delete' || !($arguments[0] ?? null) instanceof Goal) {
            return null;
        }

        $asked[] = (string) $arguments[0]->getKey();

        return in_array((string) $arguments[0]->getKey(), $allowed->getArrayCopy(), true);
    });

    $this->deletedGoalIds = new ArrayObject;
    $deleted = $this->deletedGoalIds;
    $this->mock(DeleteGoalAction::class)
        ->shouldReceive('execute')
        ->andReturnUsing(static function (User $actor, Goal $goal) use ($deleted): void {
            $deleted[] = (string) $goal->getKey();
        });
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('asks the delete right for every goal it found and deletes only the goals the actor may delete', function (): void {
    $this->allowedGoalIds[] = $this->governedGoalId;

    app(BulkDeleteGoalsAction::class)->execute($this->actor, ['ids' => [$this->governedGoalId, $this->foreignGoalId]]);

    expect($this->askedGoalIds->getArrayCopy())->toBe([$this->governedGoalId, $this->foreignGoalId])
        ->and($this->deletedGoalIds->getArrayCopy())->toBe([$this->governedGoalId]);
});

it('deletes no goal at all when the actor may delete none of them', function (): void {
    $deleted = app(BulkDeleteGoalsAction::class)->execute($this->actor, ['ids' => [$this->governedGoalId, $this->foreignGoalId]]);

    expect($deleted)->toBeEmpty()
        ->and($this->deletedGoalIds->getArrayCopy())->toBe([]);
});

it('looks the goals up only inside the tenant of the actor and only by the identifiers it was handed', function (): void {
    app(BulkDeleteGoalsAction::class)->execute($this->actor, ['ids' => [$this->governedGoalId]]);

    $goalQueries = array_values(array_filter(
        $this->connection->queries,
        static fn (array $query): bool => str_contains($query['sql'], 'from "goals"'),
    ));

    expect($goalQueries)->toHaveCount(1);

    $shape = new QueryShape($goalQueries[0]['sql'], $goalQueries[0]['bindings']);

    expect($shape->isScopedToTenant('goals', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->isKeyedTo('goals', $this->governedGoalId))->toBeTrue()
        ->and($shape->hasBinding($this->foreignGoalId))->toBeFalse();
});
