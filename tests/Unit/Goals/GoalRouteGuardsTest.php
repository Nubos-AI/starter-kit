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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Response;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\ModelStub;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
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
        expect(RouteShape::named($name)->constraints())->toHaveKey('goal');
    }
});

it('keeps the bulk deletion on its own path so it never collides with a single goal', function (): void {
    expect(RouteShape::named('goals.bulkDestroy')->uri())->toContain('goals/bulk-delete')
        ->and(RouteShape::named('goals.bulkDestroy')->methods())->toContain('POST');
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
        ->and($offenders(['app/Actions/Goals', 'app/Support/Goals'], '/GoalPeriod::query\(\)->(create|firstOrCreate)/'))->toBe([])
        ->and($offenders(['app/Enums/Authorization', 'app/Support/Authorization'], '/[\'"]goals\.[a-z]/'))->toBe([]);
});

it('keeps the goal progress path off the queue and never strips every scope at once', function (): void {
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

    expect($offenders($directories, '/ShouldQueue|Bus::batch|dispatch\(/'))->toBe([])
        ->and($offenders($directories, '/withoutGlobalScopes\(\s*\)/'))->toBe([])
        ->and((string) file_get_contents(base_path('app/Support/Goals/GoalProgressCalculator.php')))
        ->toContain('withoutTenantScope');
});

it('fans the scan out over every due goal and reports the outcome on both paths', function (): void {
    $workflow = (string) file_get_contents(base_path('app/Workflows/Goals/GoalProgressScanWorkflow.php'));

    expect($workflow)->toContain('$due = yield $activity->dueGoalIds();')
        ->and($workflow)->toContain('foreach ($goalIds as $goalId)')
        ->and($workflow)->toContain('yield $activity->computeGoalProgress($goalId)')
        ->and($workflow)->toContain("\$logger->error('Goal progress scan failed.'")
        ->and($workflow)->toContain("\$logger->info('Goal progress scan finished.'")
        ->and($workflow)->toContain('withMaximumAttempts(1)');
});
