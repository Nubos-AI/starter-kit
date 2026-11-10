<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Http\Request;
use Tests\Support\AccessContext;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('available-teams-tenant');
    $this->user = AccessContext::user($this->tenant, [], 'available-teams-user');

    app()->instance(ModuleRegistry::class, new class extends ModuleRegistry
    {
        /**
         * @return list<string>
         */
        public function all(): array
        {
            return [];
        }
    });

    /** @var callable():?QueryShape */
    $this->availableTeamsQuery = function (): ?QueryShape {
        $request = Request::create('/dashboard');
        $user = $this->user;
        $request->setUserResolver(static fn (): User => $user);

        $shared = app(HandleInertiaRequests::class)->share($request);

        return QueryShape::attemptedBy(static fn (): mixed => ($shared['availableTeams'])());
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('offers only the live teams of the session user that belong to the user tenant', function (): void {
    $shape = ($this->availableTeamsQuery)();

    expect($shape)->not->toBeNull()
        ->and($shape->targets('teams'))->toBeTrue()
        ->and($shape->isScopedToTenant('teams', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hidesSoftDeleted('teams'))->toBeTrue();
});
