<?php

declare(strict_types=1);

use App\Actions\Goals\BulkDeleteGoalsAction;
use App\Actions\Goals\CreateGoalAction;
use App\Actions\Goals\DeleteGoalAction;
use App\Actions\Goals\UpdateGoalAction;
use App\Http\Controllers\Goals\GoalsController;
use App\Http\Middleware\Api\EnsureRecordAbility;
use App\Http\Middleware\Api\EnsureReportAbility;
use App\Models\Goal;
use App\Models\User;
use App\Support\Goals\GoalEditorPresenter;
use App\Support\Teams\TeamSegment;
use App\Workflows\Goals\GoalProgressScanWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Response;
use Temporal\Exception\Failure\ActivityFailure;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\ModelStub;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\Support\WorkflowRun;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    URL::defaults([TeamSegment::key() => 'acme']);

    $this->createGoal = Mockery::mock(CreateGoalAction::class);
    $this->updateGoal = Mockery::mock(UpdateGoalAction::class);
    $this->deleteGoal = Mockery::mock(DeleteGoalAction::class);
    $this->bulkDeleteGoals = Mockery::mock(BulkDeleteGoalsAction::class);
    $this->presenter = Mockery::mock(GoalEditorPresenter::class);

    $this->controller = new GoalsController(
        $this->createGoal,
        $this->updateGoal,
        $this->deleteGoal,
        $this->bulkDeleteGoals,
        $this->presenter,
    );

    /** @var callable(array<string, mixed>):Request */
    $this->request = function (array $payload = []): Request {
        $request = Request::create('/goals', 'POST', $payload);
        $user = AccessContext::user($this->tenant);

        $request->setUserResolver(static fn (): ?User => $user);

        return $request;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    URL::defaults([TeamSegment::key() => null]);
});

it('guards every goal page behind authentication and a verified address', function (): void {
    foreach (['goals.index', 'goals.create', 'goals.store', 'goals.edit', 'goals.update', 'goals.destroy', 'goals.bulkDestroy'] as $name) {
        $route = RouteShape::named($name);

        expect($route->resolvedMiddleware())->toContain(Authenticate::class)
            ->and($route->resolvedMiddleware())->toContain(EnsureEmailIsVerified::class)
            ->and($route->handledBy())->toContain('GoalsController');
    }
});

it('accepts a goal only as a ulid so no foreign key shape can reach the resolver', function (): void {
    foreach (['goals.edit', 'goals.update', 'goals.destroy'] as $name) {
        $pattern = (string) (RouteShape::named($name)->constraints()['goal'] ?? '');

        expect(preg_match("#^(?:{$pattern})$#", '01JBRT0000000000000000000A'))->toBe(1)
            ->and(preg_match("#^(?:{$pattern})$#", '550e8400-e29b-41d4-a716-446655440000'))->toBe(0)
            ->and(preg_match("#^(?:{$pattern})$#", '42'))->toBe(0)
            ->and(preg_match("#^(?:{$pattern})$#", '..%2F01JBRT0000000000000000000A'))->toBe(0);
    }
});

it('keeps the bulk deletion on its own path so it never collides with a single goal', function (): void {
    expect(RouteShape::nameDispatchedFor('POST', '/acme/goals/bulk-delete'))->toBe('goals.bulkDestroy')
        ->and(RouteShape::nameDispatchedFor('DELETE', '/acme/goals/bulk-delete'))->toBeNull()
        ->and(RouteShape::nameDispatchedFor('PUT', '/acme/goals/bulk-delete'))->toBeNull();
});

it('guards the read only goal api with the record read ability and the report ability', function (): void {
    $index = RouteShape::matching('GET', 'api/v1/goals');
    $show = RouteShape::matching('GET', 'api/v1/goals/{goal}');

    expect($index->resolvedMiddleware())->toContain(EnsureRecordAbility::class.':read')
        ->and($show->resolvedMiddleware())->toContain(EnsureRecordAbility::class.':read')
        ->and($show->resolvedMiddleware())->toContain(EnsureReportAbility::class)
        ->and($index->resolvedMiddleware())->not->toContain(EnsureReportAbility::class);
});

it('exposes no writing goal endpoint over the api', function (): void {
    foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
        expect(fn (): RouteShape => RouteShape::matching($method, 'api/v1/goals'))
            ->toThrow(RuntimeException::class);
    }
});

it('refuses to store a goal before the action is ever asked', function (): void {
    GateSpy::allowing();

    $this->createGoal->shouldNotReceive('execute');

    expect(fn (): RedirectResponse => $this->controller->store(($this->request)(['name' => 'Revenue'])))
        ->toThrow(AuthorizationException::class);
});

it('refuses to open the create form before the editor payload is ever built', function (): void {
    GateSpy::allowing();

    $this->presenter->shouldNotReceive('payload');

    expect(fn (): Response => $this->controller->create(($this->request)()))
        ->toThrow(AuthorizationException::class);
});

it('refuses to list goals before it reads a single row', function (): void {
    GateSpy::allowing();

    expect(fn (): Response => $this->controller->index(($this->request)()))
        ->toThrow(AuthorizationException::class);
});

it('refuses a bulk deletion before the action is ever asked', function (): void {
    GateSpy::allowing();

    $this->bulkDeleteGoals->shouldNotReceive('execute');

    expect(fn (): RedirectResponse => $this->controller->bulkDestroy(($this->request)(['ids' => [ModelStub::ulid('goal')]])))
        ->toThrow(AuthorizationException::class);
});

it('refuses a caller who is not signed in at all', function (): void {
    GateSpy::allowing('viewAny', 'create');

    $request = Request::create('/goals', 'POST');
    $request->setUserResolver(static fn (): ?User => null);

    expect(fn (): RedirectResponse => $this->controller->store($request))
        ->toThrow(AuthorizationException::class);
});

it('hands the acting user and the raw input to the create action once it is allowed', function (): void {
    GateSpy::allowing('create');

    $seen = [];

    $this->createGoal->shouldReceive('execute')->once()->andReturnUsing(
        function (User $actor, array $input) use (&$seen): Goal {
            $seen = ['actor' => (string) $actor->getKey(), 'input' => $input];

            return ModelStub::make(Goal::class, ['tenant_id' => $this->tenant->getKey()]);
        },
    );

    $request = ($this->request)(['name' => 'Revenue', 'target_value' => '1000']);

    $this->controller->store($request);

    expect($seen['actor'])->toBe((string) $request->user()->getKey())
        ->and($seen['input'])->toBe(['name' => 'Revenue', 'target_value' => '1000']);
});

it('keeps the goal implementation free of recursive sql, form requests and its own permission names', function (): void {
    /** @var callable(list<string>, string):list<string> */
    $offenders = static function (array $directories, string $pattern): array {
        $hits = [];

        foreach ($directories as $directory) {
            $path = base_path($directory);

            if (!is_dir($path)) {
                continue;
            }

            /** @var iterable<SplFileInfo> $files */
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'php'
                    && preg_match($pattern, (string) file_get_contents($file->getPathname())) === 1) {
                    $hits[] = $file->getPathname();
                }
            }
        }

        return $hits;
    };

    expect($offenders(['app/Support/Goals'], '/with\s+recursive/i'))->toBe([])
        ->and($offenders(['app/Http/Controllers/Goals', 'app/Actions/Goals'], '/FormRequest/'))->toBe([])
        ->and($offenders(['app/Enums/Authorization', 'app/Support/Authorization'], '/[\'"]goals\.[a-z]/'))->toBe([]);
});

it('never strips every scope at once on the goal progress path', function (): void {
    /** @var callable(list<string>, string):list<string> */
    $offenders = static function (array $directories, string $pattern): array {
        $hits = [];

        foreach ($directories as $directory) {
            /** @var iterable<SplFileInfo> $files */
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory)));

            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'php'
                    && preg_match($pattern, (string) file_get_contents($file->getPathname())) === 1) {
                    $hits[] = $file->getPathname();
                }
            }
        }

        return $hits;
    };

    $directories = ['app/Support/Goals', 'app/Workflows/Goals', 'app/Activities/Goals'];

    expect($offenders($directories, '/withoutGlobalScopes\(\s*\)/'))->toBe([]);
});

it('fans the scan out over every due goal and reports the outcome on both paths', function (): void {
    $first = ModelStub::ulid('due-goal-a');
    $second = ModelStub::ulid('due-goal-b');

    $finished = WorkflowRun::start(fn (): Generator => (new GoalProgressScanWorkflow)->run());
    $finished->resume([$first, $second])->resume(true)->resume(false);

    $failed = WorkflowRun::start(fn (): Generator => (new GoalProgressScanWorkflow)->run());
    $failed->resume([$first]);

    expect(fn (): WorkflowRun => $failed->fail(new ActivityFailure(1, 2, 'GoalProgress', 'compute', 0, 'identity')))
        ->toThrow(ActivityFailure::class);

    WorkflowRun::end();

    expect(array_column($finished->activities(), 'arguments'))->toBe([[], [$first], [$second]])
        ->and($finished->isFinished())->toBeTrue()
        ->and($finished->logs)->toBe([[
            'level' => 'info',
            'message' => 'Goal progress scan finished.',
            'context' => ['goals' => 2, 'failures' => 1],
        ]])
        ->and($failed->logs)->toBe([[
            'level' => 'error',
            'message' => 'Goal progress scan failed.',
            'context' => ['goals' => 1, 'failures' => 0],
        ]]);
});
