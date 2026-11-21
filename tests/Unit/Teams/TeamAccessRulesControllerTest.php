<?php

declare(strict_types=1);

use App\Actions\Teams\DeleteTeamRecordAccessRuleAction;
use App\Actions\Teams\UpsertTeamRecordAccessRuleAction;
use App\Exceptions\Engine\InvalidFilterTreeException;
use App\Http\Controllers\Teams\TeamAccessRulesController;
use App\Support\Authorization\ObjectTypeAudience;
use App\Support\Engine\SystemFilterFields;
use App\Support\Teams\TeamAccessRuleGuard;
use App\Support\Teams\TeamSegment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    app()->setLocale('de');
    URL::defaults([TeamSegment::key() => 'nubos']);

    $this->tenant = AccessContext::tenant('acme');
    $this->tenantId = (string) $this->tenant->getKey();
    $this->actor = AccessContext::actAs(AccessContext::user($this->tenant));

    AccessContext::grant('companies.view');

    StaticQueryConnection::install(fn (string $sql): array => match (true) {
        str_contains($sql, 'from "teams"') => [['id' => ModelStub::ulid('sales'), 'tenant_id' => $this->tenantId, 'name' => 'Vertrieb', 'ancestor_team_ids' => '[]', 'descendant_team_ids' => '[]']],
        str_contains($sql, 'from "object_types"') => [['id' => ModelStub::ulid('companies'), 'tenant_id' => $this->tenantId, 'slug' => 'companies']],
        default => [],
    });

    $this->upsert = Mockery::mock(UpsertTeamRecordAccessRuleAction::class);
    $this->delete = Mockery::mock(DeleteTeamRecordAccessRuleAction::class);

    $this->request = function (string $method, array $input = []): Request {
        $request = Request::create('/teams/rules', $method, $input);
        $request->setUserResolver(fn () => $this->actor);

        return $request;
    };

    $this->controller = new TeamAccessRulesController(
        $this->upsert,
        $this->delete,
        Mockery::mock(TeamAccessRuleGuard::class),
        Mockery::mock(ObjectTypeAudience::class),
        Mockery::mock(SystemFilterFields::class),
    );
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('shows a filter the rule cannot use as a message on the filter field of the form', function (): void {
    GateSpy::allowing('create', 'manageAccessRules');
    $this->upsert->shouldReceive('execute')->once()->andThrow(new InvalidFilterTreeException(
        'i18n.backend.support.engine.filter_tree_validator.a_filter_value_does_not_match_the_type_of_its_field',
    ));

    $request = Request::create('/teams/rules', 'PUT', ['filter_definition' => ['combinator' => 'and', 'conditions' => []]]);
    $request->setUserResolver(fn () => $this->actor);

    try {
        $this->controller->update($request, ModelStub::ulid('sales'), ModelStub::ulid('companies'));
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe([
            'filter_definition' => ['Ein Filterwert passt nicht zum Typ seines Feldes. Bitte prüfen Sie den Filter.'],
        ]);

        return;
    }

    $this->fail('The invalid filter did not reach the form as a validation message.');
});

it('refuses to change or delete the access rules of a team the policy keeps out of reach before an action runs', function (string $method, string $classAbility): void {
    $spy = GateSpy::allowing($classAbility);
    $this->upsert->shouldNotReceive('execute');
    $this->delete->shouldNotReceive('execute');

    $call = $method === 'update'
        ? fn () => $this->controller->update(($this->request)('PUT', ['filter_definition' => ['combinator' => 'and', 'conditions' => []]]), ModelStub::ulid('sales'), ModelStub::ulid('companies'))
        : fn () => $this->controller->destroy(($this->request)('DELETE'), ModelStub::ulid('sales'), ModelStub::ulid('companies'));

    expect($call)->toThrow(AuthorizationException::class)
        ->and($spy->abilities())->toBe([$classAbility, 'manageAccessRules'])
        ->and($spy->calls[1]['arguments'][0]->getKey())->toBe(ModelStub::ulid('sales'));
})->with([
    'update' => ['update', 'create'],
    'destroy' => ['destroy', 'deleteAny'],
]);

it('refuses to show the access rules of a team the policy keeps out of reach', function (): void {
    $spy = GateSpy::allowing('viewAny');

    expect(fn () => $this->controller->index(($this->request)('GET'), ModelStub::ulid('sales')))->toThrow(AuthorizationException::class)
        ->and($spy->abilities())->toBe(['viewAny', 'viewAccessRules']);
});

it('deletes the access rule of a reachable team once the policy allows it', function (): void {
    GateSpy::allowing('deleteAny', 'manageAccessRules');
    $this->delete->shouldReceive('execute')->once()
        ->withArgs(fn ($team, $objectType): bool => $team->getKey() === ModelStub::ulid('sales') && $objectType->getKey() === ModelStub::ulid('companies'));

    $this->controller->destroy(($this->request)('DELETE'), ModelStub::ulid('sales'), ModelStub::ulid('companies'));
});

it('tells a manager who may view but not change the rules of the team why the rules stay read only', function (bool $mayManage, ?string $reason): void {
    AccessContext::grant();
    GateSpy::allowing(...($mayManage ? ['viewAny', 'viewAccessRules', 'manageAccessRules'] : ['viewAny', 'viewAccessRules']));
    $request = ($this->request)('GET');
    $request->setLaravelSession(new Store('test', new ArraySessionHandler(60)));
    app()->instance('request', $request);

    $props = $this->controller->index($request, ModelStub::ulid('sales'))->toResponse($request)->original->getData()['page']['props'];

    expect($props['canManage'])->toBe($mayManage)
        ->and($props['manageReason'])->toBe($reason);
})->with([
    'restricted by the rules' => [false, 'Diese Zugriffsregeln schränken Sie selbst ein, weil Sie dem Team oder einem seiner Unterteams angehören. Ändern kann sie nur eine Administration.'],
    'free to change them' => [true, null],
]);
