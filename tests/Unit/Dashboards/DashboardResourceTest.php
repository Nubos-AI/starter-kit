<?php

declare(strict_types=1);

use App\Enums\Dashboards\DashboardActionRefusalReason;
use App\Http\Resources\Dashboards\DashboardResource;
use App\Http\Resources\Dashboards\DashboardShareResource;
use App\Http\Resources\Dashboards\DashboardWidgetResource;
use App\Models\Dashboard;
use App\Models\DashboardShare;
use App\Models\DashboardWidget;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->viewer = AccessContext::user($this->tenant, [], 'resource-viewer');

    $this->dashboard = ModelStub::make(Dashboard::class, [
        'id' => ModelStub::ulid('resource-dashboard'),
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => ModelStub::ulid('resource-owner'),
        'is_tenant_wide' => false,
        'name' => 'Sales board',
        'description' => 'Numbers',
    ]);

    /** @var callable(?User):Request */
    $this->request = function (?User $user): Request {
        $request = Request::create('/dashboards', 'GET');
        $request->setUserResolver(static fn (): ?User => $user);

        return $request;
    };

    /** @var callable(Dashboard, ?User):array<string, mixed> */
    $this->row = fn (Dashboard $dashboard, ?User $user): array => (new DashboardResource($dashboard))
        ->resolve(($this->request)($user));
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('names the write capability as can_update and never leaks a can_edit flag to the client', function (): void {
    GateSpy::allowing('view', 'update');

    $row = ($this->row)($this->dashboard, $this->viewer);

    expect($row)->toHaveKeys(['can_update', 'can_delete', 'can_share'])
        ->and($row)->not->toHaveKey('can_edit')
        ->and($row['can_update'])->toBeTrue()
        ->and($row['can_delete'])->toBeFalse()
        ->and($row['can_share'])->toBeFalse();
});

it('tells a reading grantee that the refusal is about ownership and not about visibility', function (): void {
    GateSpy::allowing('view');

    $row = ($this->row)($this->dashboard, $this->viewer);

    expect($row['update_reason'])->toBe(DashboardActionRefusalReason::NotOwner->value)
        ->and($row['delete_reason'])->toBe(DashboardActionRefusalReason::NotOwner->value)
        ->and($row['share_reason'])->toBe(DashboardActionRefusalReason::NotOwner->value);
});

it('tells a caller who may not even read the dashboard that it is not visible', function (): void {
    GateSpy::allowing();

    $row = ($this->row)($this->dashboard, $this->viewer);

    expect($row['can_update'])->toBeFalse()
        ->and($row['update_reason'])->toBe(DashboardActionRefusalReason::NotVisible->value)
        ->and($row['delete_reason'])->toBe(DashboardActionRefusalReason::NotVisible->value)
        ->and($row['share_reason'])->toBe(DashboardActionRefusalReason::NotVisible->value);
});

it('leaves no refusal reason behind once the ability is granted', function (): void {
    GateSpy::allowing('view', 'update', 'delete', 'share');

    $row = ($this->row)($this->dashboard, $this->viewer);

    expect($row['update_reason'])->toBeNull()
        ->and($row['delete_reason'])->toBeNull()
        ->and($row['share_reason'])->toBeNull();
});

it('refuses every ability to an unauthenticated caller without asking the gate', function (): void {
    $spy = GateSpy::allowing('view', 'update', 'delete', 'share');

    $row = ($this->row)($this->dashboard, null);

    expect($row['can_update'])->toBeFalse()
        ->and($row['can_delete'])->toBeFalse()
        ->and($row['can_share'])->toBeFalse()
        ->and($row['is_owner'])->toBeFalse()
        ->and($row['is_default'])->toBeFalse()
        ->and($spy->calls)->toBe([]);
});

it('marks the owner and the personal start page from the acting user rather than from the row', function (): void {
    GateSpy::allowing('view');

    $owner = AccessContext::user($this->tenant, [
        'id' => $this->dashboard->owner_id,
        'default_dashboard_id' => $this->dashboard->getKey(),
    ], 'resource-owner');

    $row = ($this->row)($this->dashboard, $owner);

    expect($row['is_owner'])->toBeTrue()
        ->and($row['is_default'])->toBeTrue()
        ->and(($this->row)($this->dashboard, $this->viewer)['is_default'])->toBeFalse();
});

it('reports the definer widget signal as a boolean even when the query left it unset', function (): void {
    GateSpy::allowing('view');

    $row = ($this->row)($this->dashboard, $this->viewer);

    $marked = ModelStub::make(Dashboard::class, [
        'id' => ModelStub::ulid('marked'),
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => ModelStub::ulid('resource-owner'),
        'has_definer_widget' => 1,
    ]);

    expect($row['has_definer_widget'])->toBeFalse()
        ->and(($this->row)($marked, $this->viewer)['has_definer_widget'])->toBeTrue();
});

it('hands the share row the short grantee key and the resolved grantee name', function (): void {
    $team = ModelStub::make(Team::class, [
        'id' => ModelStub::ulid('share-team'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Vertrieb',
    ]);

    $share = ModelStub::make(DashboardShare::class, [
        'id' => ModelStub::ulid('share'),
        'tenant_id' => $this->tenant->getKey(),
        'dashboard_id' => $this->dashboard->getKey(),
        'grantee_type' => $team->getMorphClass(),
        'grantee_id' => $team->getKey(),
        'can_edit' => true,
    ], ['grantee' => $team]);

    $row = (new DashboardShareResource($share))->resolve(($this->request)($this->viewer));

    expect($row['grantee_type'])->toBe('team')
        ->and($row['grantee_id'])->toBe($team->getKey())
        ->and($row['grantee_name'])->toBe('Vertrieb')
        ->and($row['can_edit'])->toBeTrue();
});

it('leaves the grantee name empty when the grantee behind the share no longer resolves', function (): void {
    $share = ModelStub::make(DashboardShare::class, [
        'id' => ModelStub::ulid('orphan-share'),
        'tenant_id' => $this->tenant->getKey(),
        'dashboard_id' => $this->dashboard->getKey(),
        'grantee_type' => Dashboard::class,
        'grantee_id' => ModelStub::ulid('gone'),
        'can_edit' => false,
    ], ['grantee' => null]);

    $row = (new DashboardShareResource($share))->resolve(($this->request)($this->viewer));

    expect($row['grantee_name'])->toBeNull()
        ->and($row['grantee_type'])->toBeNull();
});

it('exposes a widget without ever revealing which tenant carries it', function (): void {
    $widget = ModelStub::make(DashboardWidget::class, [
        'id' => ModelStub::ulid('resource-widget'),
        'tenant_id' => $this->tenant->getKey(),
        'dashboard_id' => $this->dashboard->getKey(),
        'title' => 'Umsatz',
        'definition' => ['aggregation_type' => 'count'],
        'position' => 2,
        'column_span' => 3,
    ]);

    $row = (new DashboardWidgetResource($widget))->resolve(($this->request)($this->viewer));

    expect($row)->not->toHaveKey('tenant_id')
        ->and($row['position'])->toBe(2)
        ->and($row['column_span'])->toBe(3)
        ->and($row['definition'])->toBe(['aggregation_type' => 'count']);
});
