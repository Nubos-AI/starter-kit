<?php

declare(strict_types=1);

use App\Actions\Aging\BulkDeleteAgingRulesAction;
use App\Actions\Aging\CreateAgingRuleAction;
use App\Actions\Aging\DeleteAgingRuleAction;
use App\Actions\Aging\UpdateAgingRuleAction;
use App\Http\Controllers\Aging\AgingRulesController;
use App\Models\AgingRule;
use App\Models\ObjectType;
use App\Models\User;
use App\Policies\Aging\AgingRulePolicy;
use App\Support\Teams\TeamSegment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    URL::defaults([TeamSegment::key() => 'core']);
    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('aging-owner-type'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ]);
    $this->otherObjectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('aging-other-type'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'contacts',
    ]);
    $this->rule = ModelStub::make(AgingRule::class, [
        'id' => ModelStub::ulid('aging-rule'),
        'object_type_id' => $this->objectType->getKey(),
        'name' => 'Stalled deals',
    ]);

    $this->createCalls = 0;
    $this->updateCalls = 0;
    $this->deleteCalls = 0;
    $this->bulkCalls = 0;

    $this->controllerWith = function (): AgingRulesController {
        $create = new class($this) extends CreateAgingRuleAction
        {
            public function __construct(private object $probe) {}

            public function execute(ObjectType $objectType, array $input): AgingRule
            {
                $this->probe->createCalls++;
                $this->probe->createInput = $input;

                return $this->probe->rule;
            }
        };

        $update = new class($this) extends UpdateAgingRuleAction
        {
            public function __construct(private object $probe) {}

            public function execute(AgingRule $agingRule, array $input): AgingRule
            {
                $this->probe->updateCalls++;

                return $agingRule;
            }
        };

        $delete = new class($this) extends DeleteAgingRuleAction
        {
            public function __construct(private object $probe) {}

            public function execute(AgingRule $agingRule): void
            {
                $this->probe->deleteCalls++;
            }
        };

        $bulk = new class($this) extends BulkDeleteAgingRulesAction
        {
            public function __construct(private object $probe) {}

            public function execute(User $actor, array $input, ?Model $scope = null): Collection
            {
                $this->probe->bulkCalls++;

                return new Collection;
            }
        };

        return new AgingRulesController($create, $update, $delete, $bulk);
    };

    $this->requestBy = function (User $user, array $payload = []): Request {
        $request = Request::create('/aging-rules', 'POST', $payload);
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    };
});

afterEach(function (): void {
    URL::defaults([TeamSegment::key() => null]);
    AccessContext::forgetTenant();
});

it('grants the aging rule policy only to the permissions the object type screen demands', function (): void {
    $policy = new AgingRulePolicy;

    AccessContext::grant('object-types.view');
    $viewer = AccessContext::user($this->tenant, [], 'aging-viewer');

    expect($policy->viewAny($viewer, $this->objectType))->toBeTrue()
        ->and($policy->create($viewer, $this->objectType))->toBeFalse()
        ->and($policy->deleteAny($viewer, $this->objectType))->toBeFalse()
        ->and($policy->update($viewer, $this->rule))->toBeFalse()
        ->and($policy->delete($viewer, $this->rule))->toBeFalse();

    AccessContext::grant('object-types.view', 'object-types.update');
    $manager = AccessContext::user($this->tenant, [], 'aging-manager');

    expect($policy->create($manager, $this->objectType))->toBeTrue()
        ->and($policy->deleteAny($manager, $this->objectType))->toBeTrue()
        ->and($policy->update($manager, $this->rule))->toBeTrue()
        ->and($policy->delete($manager, $this->rule))->toBeTrue();
});

it('refuses to create a rule without the object type update permission and never calls the action', function (): void {
    AccessContext::grant('object-types.view');
    $viewer = AccessContext::actAs(AccessContext::user($this->tenant, [], 'aging-viewer'));

    $controller = ($this->controllerWith)();

    expect(fn (): RedirectResponse => $controller->store(($this->requestBy)($viewer, ['name' => 'Blocked']), $this->objectType))
        ->toThrow(AuthorizationException::class)
        ->and($this->createCalls)->toBe(0);
});

it('refuses to update and to delete a rule without the object type update permission', function (): void {
    AccessContext::grant('object-types.view');
    $viewer = AccessContext::actAs(AccessContext::user($this->tenant, [], 'aging-viewer'));

    $controller = ($this->controllerWith)();

    expect(fn (): RedirectResponse => $controller->update(($this->requestBy)($viewer), $this->objectType, $this->rule))
        ->toThrow(AuthorizationException::class)
        ->and(fn (): RedirectResponse => $controller->destroy($this->objectType, $this->rule))
        ->toThrow(AuthorizationException::class)
        ->and(fn (): RedirectResponse => $controller->bulkDestroy(($this->requestBy)($viewer, ['ids' => []]), $this->objectType))
        ->toThrow(AuthorizationException::class)
        ->and($this->updateCalls)->toBe(0)
        ->and($this->deleteCalls)->toBe(0)
        ->and($this->bulkCalls)->toBe(0);
});

it('hands the whole payload to the create action once the permission is there', function (): void {
    AccessContext::grant('object-types.view', 'object-types.update');
    $manager = AccessContext::actAs(AccessContext::user($this->tenant, [], 'aging-manager'));

    $response = ($this->controllerWith)()->store(
        ($this->requestBy)($manager, ['name' => 'Stalled deals', 'clock' => 'updated_at']),
        $this->objectType,
    );

    expect($this->createCalls)->toBe(1)
        ->and($this->createInput)->toBe(['name' => 'Stalled deals', 'clock' => 'updated_at'])
        ->and($response->getTargetUrl())->toContain('companies');
});

it('answers not found when the addressed rule belongs to another object type', function (): void {
    AccessContext::grant('object-types.view', 'object-types.update');
    $manager = AccessContext::actAs(AccessContext::user($this->tenant, [], 'aging-manager'));

    $controller = ($this->controllerWith)();

    expect(fn (): RedirectResponse => $controller->update(($this->requestBy)($manager), $this->otherObjectType, $this->rule))
        ->toThrow(NotFoundHttpException::class)
        ->and(fn (): RedirectResponse => $controller->destroy($this->otherObjectType, $this->rule))
        ->toThrow(NotFoundHttpException::class)
        ->and($this->updateCalls)->toBe(0)
        ->and($this->deleteCalls)->toBe(0);
});
