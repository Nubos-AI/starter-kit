<?php

declare(strict_types=1);

use App\Actions\Aging\CreateAgingRuleAction;
use App\Actions\Aging\DeleteAgingRuleAction;
use App\Actions\Aging\UpdateAgingRuleAction;
use App\Actions\Dashboards\AddDashboardWidgetAction;
use App\Actions\Dashboards\CreateDashboardAction;
use App\Actions\Dashboards\DeleteDashboardAction;
use App\Actions\Dashboards\RemoveDashboardWidgetAction;
use App\Actions\Dashboards\UpdateDashboardAction;
use App\Actions\Dashboards\UpdateDashboardWidgetAction;
use App\Actions\Export\CreateExportFieldPresetAction;
use App\Actions\Export\DeleteExportFieldPresetAction;
use App\Actions\Export\UpdateExportFieldPresetAction;
use App\Actions\Goals\CreateGoalAction;
use App\Actions\Goals\DeleteGoalAction;
use App\Actions\Goals\UpdateGoalAction;
use App\Actions\Import\CreateImportMappingPresetAction;
use App\Actions\Import\DeleteImportMappingPresetAction;
use App\Actions\Import\UpdateImportMappingPresetAction;
use App\Actions\Reports\CreateReportAction;
use App\Actions\Reports\DeleteReportAction;
use App\Actions\Reports\UpdateReportAction;
use App\Actions\Segments\CreateSegmentAction;
use App\Actions\Segments\DeleteSegmentAction;
use App\Actions\Segments\UpdateSegmentAction;
use App\Actions\Skills\CreateSkillAction;
use App\Actions\Skills\DeleteSkillAction;
use App\Actions\Skills\UpdateSkillAction;
use App\DTOs\ConfigBundle\ArtifactWriteResult;
use App\DTOs\ConfigBundle\BundleArtifact;
use App\Enums\Authorization\RoleAuthority;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\ConfigBundle\ArtifactWriteAction;
use App\Enums\Goals\GoalScopeType;
use App\Enums\Promotion\DiffState;
use App\Enums\Reports\ReportExecutionMode;
use App\Exceptions\Engine\InvalidFilterTreeException;
use App\Handlers\ConfigBundle\AnalyticsArtifactWriter;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Report;
use App\Models\Role;
use App\Models\Segment;
use App\Models\Skill;
use App\Support\ConfigBundle\ArtifactKindRegistry;
use App\Support\ConfigBundle\BundleWriteTenant;
use App\Support\ConfigBundle\TargetKeyResolver;
use App\Support\Tenancy\ActingUserContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();

    $escalatedRole = ModelStub::make(Role::class, [
        'id' => ModelStub::ulid('analytics-super-admin'),
        'tenant_id' => $this->tenantId,
        'name' => 'super-admin',
        'authority' => RoleAuthority::SuperAdmin->value,
    ]);

    $this->actor = RoleHolder::make(['tenant_id' => $this->tenantId], [$escalatedRole], 'analytics-actor');
    $this->plainActor = RoleHolder::make(['tenant_id' => $this->tenantId], [], 'analytics-plain-actor');
    $this->currentActor = $this->actor;

    $this->ids = new ArrayObject;
    $this->parents = new ArrayObject;
    $this->prefixes = new ArrayObject;
    $this->invalidated = new ArrayObject;
    $this->actingRuns = new ArrayObject;

    $targetKeys = Mockery::mock(TargetKeyResolver::class);

    $targetKeys->shouldReceive('idFor', 'idForTable')->andReturnUsing(function (string $kind, string $businessKey): ?string {
        $table = $kind;

        return $this->ids["{$table}|{$businessKey}"] ?? null;
    });

    $targetKeys->allows('parentIdFor')->andReturnUsing(fn (string $kind, string $businessKey, string $column): ?string => $this->parents["{$kind}|{$businessKey}|{$column}"] ?? null);

    $targetKeys->allows('knownPrefixOf')->andReturnUsing(fn (string $parent, string $businessKey): ?string => $this->prefixes["{$parent}|{$businessKey}"] ?? null);

    $targetKeys->allows('invalidate')->andReturnUsing(function (string $kind): void {
        $this->invalidated[] = $kind;
    });

    $actingUserContext = Mockery::mock(ActingUserContext::class);

    $actingUserContext->allows('run')->andReturnUsing(function (string $tenantId, string $actingUserId, Closure $callback): void {
        $this->actingRuns[] = ['tenantId' => $tenantId, 'actingUserId' => $actingUserId];

        $callback($this->currentActor);
    });

    $this->writeTenant = new BundleWriteTenant;

    $this->createDashboard = Mockery::mock(CreateDashboardAction::class);
    $this->addWidget = Mockery::mock(AddDashboardWidgetAction::class);
    $this->createReport = Mockery::mock(CreateReportAction::class);
    $this->createSegment = Mockery::mock(CreateSegmentAction::class);
    $this->createSkill = Mockery::mock(CreateSkillAction::class);

    $this->writer = new AnalyticsArtifactWriter(
        $actingUserContext,
        $this->addWidget,
        $this->writeTenant,
        Mockery::mock(CreateAgingRuleAction::class),
        $this->createDashboard,
        Mockery::mock(CreateExportFieldPresetAction::class),
        Mockery::mock(CreateGoalAction::class),
        Mockery::mock(CreateImportMappingPresetAction::class),
        $this->createReport,
        $this->createSegment,
        $this->createSkill,
        Mockery::mock(DeleteAgingRuleAction::class),
        Mockery::mock(DeleteDashboardAction::class),
        Mockery::mock(DeleteExportFieldPresetAction::class),
        Mockery::mock(DeleteGoalAction::class),
        Mockery::mock(DeleteImportMappingPresetAction::class),
        Mockery::mock(DeleteReportAction::class),
        Mockery::mock(DeleteSegmentAction::class),
        Mockery::mock(DeleteSkillAction::class),
        Mockery::mock(RemoveDashboardWidgetAction::class),
        $targetKeys,
        Mockery::mock(UpdateAgingRuleAction::class),
        Mockery::mock(UpdateDashboardAction::class),
        Mockery::mock(UpdateDashboardWidgetAction::class),
        Mockery::mock(UpdateExportFieldPresetAction::class),
        Mockery::mock(UpdateGoalAction::class),
        Mockery::mock(UpdateImportMappingPresetAction::class),
        Mockery::mock(UpdateReportAction::class),
        Mockery::mock(UpdateSegmentAction::class),
        Mockery::mock(UpdateSkillAction::class),
    );

    /** @var callable(string, string, array<string, mixed>, DiffState):ArtifactWriteResult */
    $this->apply = fn (string $kind, string $key, array $payload = [], DiffState $state = DiffState::Added): ArtifactWriteResult => $this->writer->apply(
        $this->currentActor,
        $kind,
        new BundleArtifact($kind, $key, $payload),
        $state,
    );

    /** @var callable(ArtifactWriteResult):string */
    $this->reasonOf = fn (ArtifactWriteResult $result): string => implode(' ', $result->notes);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('carries exactly the artifact kinds of the analytics group', function (): void {
    $carried = array_values(array_filter(
        ArtifactKindRegistry::all(),
        fn (string $kind): bool => $this->writer->supports($kind),
    ));

    expect($carried)->toBe([
        ArtifactKind::Reports->value,
        ArtifactKind::Dashboards->value,
        ArtifactKind::DashboardWidgets->value,
        ArtifactKind::Goals->value,
        ArtifactKind::Segments->value,
        ArtifactKind::ExportFieldPresets->value,
        ArtifactKind::ImportMappingPresets->value,
        ArtifactKind::AgingRules->value,
        ArtifactKind::Skills->value,
    ]);
});

it('leaves an artifact kind that belongs to another writer alone', function (string $kind): void {
    expect($this->writer->supports($kind))->toBeFalse();
})->with([
    'object types' => ArtifactKind::ObjectTypes->value,
    'roles' => ArtifactKind::Roles->value,
    'webhook subscriptions' => ArtifactKind::WebhookSubscriptions->value,
]);

it('skips a diff state that asks for no write and names the state in the reason', function (DiffState $state): void {
    $result = ($this->apply)(ArtifactKind::Skills->value, 'Abschlussstärke', [], $state);

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and($result->modelId)->toBeNull()
        ->and(($this->reasonOf)($result))->toContain($state->value)
        ->and($this->invalidated->getArrayCopy())->toBe([]);
})->with([
    'unchanged' => DiffState::Unchanged,
    'removed' => DiffState::Removed,
    'conflicted' => DiffState::Conflicted,
]);

it('skips an artifact whose payload still carries the placeholder of an unresolved reference', function (): void {
    $placeholder = (string) config('engine.config_bundle.placeholder');

    $result = ($this->apply)(ArtifactKind::Segments->value, 'deal:Gewonnene', [
        'filter_definition' => ['conditions' => [['field' => 'owner_id', 'value' => $placeholder]]],
    ]);

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and($this->invalidated->getArrayCopy())->toBe([]);
});

it('refuses an artifact kind that belongs to no branch of this writer', function (): void {
    $result = ($this->apply)(ArtifactKind::ObjectTypes->value, 'deal');

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and($result->kind)->toBe(ArtifactKind::ObjectTypes->value)
        ->and($this->invalidated->getArrayCopy())->toBe([]);
});

it('hands the created report to the report action with the name taken from the business key', function (): void {
    $this->parents['reports|deal:Quartalszahlen|object_type_id'] = ModelStub::ulid('object-type');
    $this->prefixes['object-types|deal:Quartalszahlen'] = 'deal';

    $report = ModelStub::make(Report::class, ['id' => ModelStub::ulid('created-report')]);

    $this->createReport->expects('execute')
        ->withArgs(function (object $actor, array $input): bool {
            expect($actor)->toBe($this->actor)
                ->and($input['object_type_id'])->toBe(ModelStub::ulid('object-type'))
                ->and($input['name'])->toBe('Quartalszahlen')
                ->and($input['execution_mode'])->toBe(ReportExecutionMode::Viewer->value)
                ->and($input)->not->toHaveKey('key');

            return true;
        })
        ->andReturn($report);

    $result = ($this->apply)(ArtifactKind::Reports->value, 'deal:Quartalszahlen', [
        'description' => 'Zahlen des Quartals',
        'execution_mode' => ReportExecutionMode::Viewer->value,
        'filter_definition' => [],
    ]);

    expect($result->action)->toBe(ArtifactWriteAction::Created)
        ->and($result->modelId)->toBe((string) $report->getKey())
        ->and($this->invalidated->getArrayCopy())->toBe([ArtifactKind::Reports->value]);
});

it('skips a definer bound report while the acting user carries no escalated authority', function (): void {
    $this->currentActor = $this->plainActor;
    $this->parents['reports|deal:Quartalszahlen|object_type_id'] = ModelStub::ulid('object-type');
    $this->prefixes['object-types|deal:Quartalszahlen'] = 'deal';

    $result = ($this->apply)(ArtifactKind::Reports->value, 'deal:Quartalszahlen', [
        'execution_mode' => ReportExecutionMode::Definer->value,
    ]);

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and($result->modelId)->toBeNull()
        ->and($this->invalidated->getArrayCopy())->toBe([]);
});

it('names the unresolvable object type of a report as the awaited reference', function (): void {
    $result = ($this->apply)(ArtifactKind::Reports->value, 'deal:Quartalszahlen');

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and($result->awaitsReference())->toBeTrue()
        ->and(($this->reasonOf)($result))->toContain('object_type_id');
});

it('reports a modified report without a counterpart in the target as a missing target', function (): void {
    $this->parents['reports|deal:Quartalszahlen|object_type_id'] = ModelStub::ulid('object-type');
    $this->prefixes['object-types|deal:Quartalszahlen'] = 'deal';

    $result = ($this->apply)(ArtifactKind::Reports->value, 'deal:Quartalszahlen', [], DiffState::Modified);

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and($result->awaitsReference())->toBeFalse()
        ->and(($this->reasonOf)($result))->toContain('deal:Quartalszahlen');
});

it('skips a dashboard while the acting user carries no escalated authority', function (): void {
    $this->currentActor = $this->plainActor;

    $result = ($this->apply)(ArtifactKind::Dashboards->value, 'Kennzahlen', ['is_tenant_wide' => true]);

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and($result->modelId)->toBeNull();
});

it('takes the dashboard name from the business key and never from the payload', function (): void {
    $dashboard = ModelStub::make(Dashboard::class, ['id' => ModelStub::ulid('created-dashboard')]);

    $this->createDashboard->expects('execute')
        ->withArgs(function (object $actor, array $input): bool {
            expect($input['name'])->toBe('Kennzahlen')
                ->and($input['is_tenant_wide'])->toBeTrue()
                ->and($input['description'])->toBe('Überblick für alle');

            return true;
        })
        ->andReturn($dashboard);

    $result = ($this->apply)(ArtifactKind::Dashboards->value, 'Kennzahlen', [
        'name' => 'Aus der Nutzlast',
        'description' => 'Überblick für alle',
        'is_tenant_wide' => true,
    ]);

    expect($result->action)->toBe(ArtifactWriteAction::Created)
        ->and($result->modelId)->toBe((string) $dashboard->getKey());
});

it('skips a widget whose dashboard cannot be resolved in the target', function (): void {
    $result = ($this->apply)(ArtifactKind::DashboardWidgets->value, 'Vertriebsübersicht:4', [
        'report_id' => 'deal:Umsatz',
    ]);

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and($result->awaitsReference())->toBeTrue()
        ->and(($this->reasonOf)($result))->toContain('dashboard_id');
});

it('carries a module tile over by its key alone', function (): void {
    $dashboardId = ModelStub::ulid('bundle-dashboard');
    $this->parents['dashboard-widgets|Kennzahlen:1|dashboard_id'] = $dashboardId;

    StaticQueryConnection::install(fn (string $sql): array => str_contains($sql, 'from "dashboards"')
        ? [['id' => $dashboardId, 'tenant_id' => $this->tenantId, 'owner_id' => ModelStub::ulid('owner'), 'name' => 'Kennzahlen']]
        : []);

    $handed = null;
    $this->addWidget->expects('execute')
        ->andReturnUsing(function (object $actor, Dashboard $dashboard, array $input) use (&$handed): DashboardWidget {
            $handed = $input;

            return ModelStub::make(DashboardWidget::class, ['id' => ModelStub::ulid('bundle-widget'), 'tenant_id' => $this->tenantId]);
        });

    $result = ($this->apply)(ArtifactKind::DashboardWidgets->value, 'Kennzahlen:1', [
        'source_key' => 'charge.mrr',
        'chart_type' => 'metric',
        'title' => 'MRR',
        'column_span' => 1,
    ]);

    StaticQueryConnection::uninstall();

    expect($result->action)->toBe(ArtifactWriteAction::Created)
        ->and($handed)->toBe(['title' => 'MRR', 'column_span' => 1, 'source_key' => 'charge.mrr', 'chart_type' => 'metric']);
});

it('skips a goal whose scope names anything but the tenant', function (string $scope): void {
    $result = ($this->apply)(ArtifactKind::Goals->value, 'deal:Umsatz:Wachstumsziel', ['scope_type' => $scope]);

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and($result->modelId)->toBeNull()
        ->and($result->awaitsReference())->toBeFalse();
})->with([
    'person' => GoalScopeType::User->value,
    'team' => GoalScopeType::Team->value,
]);

it('skips a tenant goal whose report cannot be resolved in the target', function (): void {
    $result = ($this->apply)(ArtifactKind::Goals->value, 'deal:Umsatz:Wachstumsziel', [
        'scope_type' => GoalScopeType::Tenant->value,
    ]);

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and($result->awaitsReference())->toBeTrue()
        ->and(($this->reasonOf)($result))->toContain('report_id');
});

it('skips a segment whose object type cannot be resolved in the target', function (): void {
    $result = ($this->apply)(ArtifactKind::Segments->value, 'deal:Gewonnene', ['filter_definition' => []]);

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and($result->awaitsReference())->toBeTrue()
        ->and(($this->reasonOf)($result))->toContain('object_type_id');
});

it('names the segment fields that no action accepts and hands none of them to the action', function (): void {
    $this->parents['segments|deal:Gewonnene|object_type_id'] = ModelStub::ulid('object-type');
    $this->prefixes['object-types|deal:Gewonnene'] = 'deal';

    $segment = ModelStub::make(Segment::class, ['id' => ModelStub::ulid('created-segment')]);

    $this->createSegment->expects('execute')
        ->withArgs(function (object $actor, array $input): bool {
            expect(array_keys($input))->toBe(['object_type_id', 'name', 'filter_definition'])
                ->and($input['name'])->toBe('Gewonnene');

            return true;
        })
        ->andReturn($segment);

    $result = ($this->apply)(ArtifactKind::Segments->value, 'deal:Gewonnene', [
        'is_system' => true,
        'is_default' => true,
        'i18n_labels' => ['de' => 'Gewonnene'],
        'filter_definition' => [],
    ]);

    expect($result->action)->toBe(ArtifactWriteAction::Created)
        ->and($result->notes)->not->toBe([]);
});

it('takes the skill name from the business key', function (): void {
    $skill = ModelStub::make(Skill::class, ['id' => ModelStub::ulid('created-skill')]);

    $this->createSkill->expects('execute')
        ->withArgs(function (object $actor, array $input): bool {
            expect($input)->toBe(['name' => 'Abschlussstärke']);

            return true;
        })
        ->andReturn($skill);

    $result = ($this->apply)(ArtifactKind::Skills->value, 'Abschlussstärke');

    expect($result->action)->toBe(ArtifactWriteAction::Created)
        ->and($result->modelId)->toBe((string) $skill->getKey())
        ->and($this->invalidated->getArrayCopy())->toBe([ArtifactKind::Skills->value]);
});

it('turns a refusal of the target action into a skip instead of letting it escape', function (string $failure, string $mention): void {
    $this->createSkill->expects('execute')->andThrow(match ($failure) {
        'validation' => ValidationException::withMessages(['name' => 'Der Name ist belegt.']),
        'missing model' => new ModelNotFoundException,
        'authorization' => new AuthorizationException,
        default => new InvalidFilterTreeException,
    });

    $result = ($this->apply)(ArtifactKind::Skills->value, 'Abschlussstärke');

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and($result->modelId)->toBeNull()
        ->and(($this->reasonOf)($result))->toContain($mention)
        ->and($this->invalidated->getArrayCopy())->toBe([]);
})->with([
    'validation' => ['validation', 'Der Name ist belegt.'],
    'missing model' => ['missing model', 'Verweis'],
    'authorization' => ['authorization', 'nicht schreiben'],
    'invalid filter' => ['invalid filter', 'Filter'],
]);

it('lets an unexpected failure of the target action escape instead of hiding it in a note', function (): void {
    $this->createSkill->expects('execute')->andThrow(new RuntimeException('Ziel nicht erreichbar'));

    expect(fn (): ArtifactWriteResult => ($this->apply)(ArtifactKind::Skills->value, 'Abschlussstärke'))
        ->toThrow(RuntimeException::class, 'Ziel nicht erreichbar');
});

it('reports a removal whose key names nothing in the target as a missing target', function (string $kind): void {
    $result = $this->writer->remove($this->actor, $kind, 'deal:Unbekannt');

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and($result->modelId)->toBeNull()
        ->and(($this->reasonOf)($result))->toContain('deal:Unbekannt')
        ->and($this->invalidated->getArrayCopy())->toBe([]);
})->with([
    'reports' => ArtifactKind::Reports->value,
    'dashboards' => ArtifactKind::Dashboards->value,
    'dashboard widgets' => ArtifactKind::DashboardWidgets->value,
    'goals' => ArtifactKind::Goals->value,
    'segments' => ArtifactKind::Segments->value,
    'export field presets' => ArtifactKind::ExportFieldPresets->value,
    'import mapping presets' => ArtifactKind::ImportMappingPresets->value,
    'aging rules' => ArtifactKind::AgingRules->value,
    'skills' => ArtifactKind::Skills->value,
]);

it('runs the write inside the tenant of the acting user while no write frame is open', function (): void {
    $this->createSkill->expects('execute')->andReturn(ModelStub::make(Skill::class));

    ($this->apply)(ArtifactKind::Skills->value, 'Abschlussstärke');

    expect($this->actingRuns->getArrayCopy())->toBe([[
        'tenantId' => $this->tenantId,
        'actingUserId' => (string) $this->actor->getKey(),
    ]]);
});

it('runs the write inside the tenant of the open write frame instead of the tenant of the acting user', function (): void {
    $frameTenantId = ModelStub::ulid('foreign-tenant');

    $this->createSkill->expects('execute')->andReturn(ModelStub::make(Skill::class));

    $this->writeTenant->within($frameTenantId, fn (): ArtifactWriteResult => ($this->apply)(ArtifactKind::Skills->value, 'Abschlussstärke'));

    expect($this->actingRuns[0]['tenantId'])->toBe($frameTenantId)
        ->and($this->actingRuns[0]['tenantId'])->not->toBe($this->tenantId);
});

it('refuses to write at all while no target tenant can be resolved instead of reporting a silent skip', function (): void {
    $this->currentActor = RoleHolder::make(['tenant_id' => null], [], 'analytics-tenantless');

    expect(fn (): ArtifactWriteResult => ($this->apply)(ArtifactKind::Skills->value, 'Abschlussstärke'))
        ->toThrow(AuthorizationException::class);

    expect($this->actingRuns->getArrayCopy())->toBe([]);
});

it('drops the run state of every carried kind on flush and reports no further result', function (): void {
    expect($this->writer->flush($this->actor))->toBe([])
        ->and($this->invalidated->getArrayCopy())->toBe([
            ArtifactKind::Reports->value,
            ArtifactKind::Dashboards->value,
            ArtifactKind::DashboardWidgets->value,
            ArtifactKind::Goals->value,
            ArtifactKind::Segments->value,
            ArtifactKind::ExportFieldPresets->value,
            ArtifactKind::ImportMappingPresets->value,
            ArtifactKind::AgingRules->value,
            ArtifactKind::Skills->value,
        ]);
});

it('leaves every write to its actions and opens no transaction of its own', function (): void {
    $skill = ModelStub::make(Skill::class, ['id' => ModelStub::ulid('untouched-skill')]);

    $this->createSkill->expects('execute')->andReturn($skill);

    $connection = StaticQueryConnection::install(static fn (): array => []);

    $result = ($this->apply)(ArtifactKind::Skills->value, 'Abschlussstärke');

    StaticQueryConnection::uninstall();

    expect($result->action)->toBe(ArtifactWriteAction::Created)
        ->and($connection->writtenStatements)->toBe([])
        ->and($connection->openedTransactions)->toBe(0);
});

it('hands its actions the acting user it was given instead of whoever is signed in', function (): void {
    $skill = ModelStub::make(Skill::class, ['id' => ModelStub::ulid('acted-skill')]);

    Auth::setUser(AccessContext::user(AccessContext::tenant()));

    $seen = null;

    $this->createSkill->expects('execute')
        ->withArgs(function (object $actor, array $input) use (&$seen): bool {
            $seen = $actor;

            return true;
        })
        ->andReturn($skill);

    ($this->apply)(ArtifactKind::Skills->value, 'Abschlussstärke');

    expect($seen)->toBe($this->actor)
        ->and($seen)->not->toBe(Auth::user());
});
