<?php

declare(strict_types=1);

namespace App\Http\Controllers\Maintenance;

use App\Actions\Maintenance\AcquireMaintenanceLockAction;
use App\Actions\Maintenance\ReleaseMaintenanceLockAction;
use App\Enums\Maintenance\MaintenanceLockReason;
use App\Enums\Maintenance\MaintenanceLockRelease;
use App\Enums\Ui\ToastType;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\Maintenance\MaintenanceLockResource;
use App\Models\MaintenanceLock;
use App\Support\Maintenance\MaintenanceLockRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MaintenanceLockController extends Controller
{
    public function __construct(
        private readonly MaintenanceLockRegistry $registry,
        private readonly AcquireMaintenanceLockAction $acquireLock,
        private readonly ReleaseMaintenanceLockAction $releaseLock,
    ) {}

    public function show(Request $request): Response
    {
        $actor = $this->authorizePermission($request, 'maintenance.manage');

        $lock = $this->registry->activeFor((string) $actor->tenant_id);

        return Inertia::render('maintenance/Show', [
            'lock' => $lock instanceof MaintenanceLock
                ? (new MaintenanceLockResource($lock->load('acquiredBy')))->toArray($request)
                : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->authorizePermission($request, 'maintenance.manage');

        $this->acquireLock->execute($actor, (string) $actor->tenant_id, MaintenanceLockReason::Manual, $request->all());

        Inertia::flash('toast', ['type' => ToastType::Success->value, 'message' => __('i18n.backend.http.controllers.maintenance.maintenance_lock_controller.maintenance_mode_enabled')]);

        return to_route('engine.maintenance.show');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $actor = $this->authorizePermission($request, 'maintenance.manage');

        $lock = $this->registry->activeFor((string) $actor->tenant_id);

        if ($lock instanceof MaintenanceLock) {
            $this->releaseLock->execute($actor, $lock, MaintenanceLockRelease::Manual);

            Inertia::flash('toast', ['type' => ToastType::Success->value, 'message' => __('i18n.backend.http.controllers.maintenance.maintenance_lock_controller.maintenance_mode_ended')]);
        }

        return to_route('engine.maintenance.show');
    }
}
