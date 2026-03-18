<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenants;

use App\Actions\Tenants\CreateTenantAction;
use App\Actions\Tenants\DeleteTenantAction;
use App\Actions\Tenants\UpdateTenantAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenants\StoreTenantRequest;
use App\Http\Requests\Tenants\UpdateTenantRequest;
use App\Models\Tenant;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TenantController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Tenant::class);

        return Inertia::render('Tenants/Index', [
            'tenants' => $request->user()->tenants,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Tenant::class);

        return Inertia::render('Tenants/Create');
    }

    public function store(
        StoreTenantRequest $request,
        CreateTenantAction $action,
    ): RedirectResponse {
        $this->authorize('create', Tenant::class);

        $action->execute($request->user(), $request->validated());

        return redirect()->route('tenants.index');
    }

    public function show(Tenant $tenant): Response
    {
        $this->authorize('view', $tenant);

        return Inertia::render('Tenants/Settings', [
            'tenant' => $tenant,
        ]);
    }

    public function update(
        UpdateTenantRequest $request,
        Tenant $tenant,
        UpdateTenantAction $action,
    ): RedirectResponse {
        $this->authorize('update', $tenant);

        $action->execute($tenant, $request->validated());

        return redirect()->route('tenants.settings', $tenant);
    }

    public function destroy(
        Tenant $tenant,
        DeleteTenantAction $action,
    ): RedirectResponse {
        $this->authorize('delete', $tenant);

        $action->execute($tenant);

        return redirect()->route('tenants.index');
    }
}
