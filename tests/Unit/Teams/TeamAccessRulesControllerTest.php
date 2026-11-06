<?php

declare(strict_types=1);

use App\Actions\Teams\DeleteTeamRecordAccessRuleAction;
use App\Actions\Teams\UpsertTeamRecordAccessRuleAction;
use App\Exceptions\Engine\InvalidFilterTreeException;
use App\Http\Controllers\Teams\TeamAccessRulesController;
use App\Support\Authorization\ObjectTypeAudience;
use App\Support\Engine\SystemFilterFields;
use App\Support\Teams\TeamAccessRuleGuard;
use Illuminate\Http\Request;
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

    $this->tenant = AccessContext::tenant('acme');
    $this->tenantId = (string) $this->tenant->getKey();
    $this->actor = AccessContext::actAs(AccessContext::user($this->tenant));

    AccessContext::grant('companies.view');
    GateSpy::allowing('create');

    StaticQueryConnection::install(fn (string $sql): array => match (true) {
        str_contains($sql, 'from "teams"') => [['id' => ModelStub::ulid('sales'), 'tenant_id' => $this->tenantId, 'name' => 'Vertrieb']],
        str_contains($sql, 'from "object_types"') => [['id' => ModelStub::ulid('companies'), 'tenant_id' => $this->tenantId, 'slug' => 'companies']],
        default => [],
    });

    $this->upsert = Mockery::mock(UpsertTeamRecordAccessRuleAction::class);

    $this->controller = new TeamAccessRulesController(
        $this->upsert,
        Mockery::mock(DeleteTeamRecordAccessRuleAction::class),
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
