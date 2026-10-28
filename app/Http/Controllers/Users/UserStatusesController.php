<?php

declare(strict_types=1);

namespace App\Http\Controllers\Users;

use App\Actions\Users\SetUserStatusAction;
use App\Enums\Ui\ToastType;
use App\Exceptions\Authorization\SelfLockoutException;
use App\Http\Controllers\Abstracts\Controller;
use App\Support\Users\TenantUserResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class UserStatusesController extends Controller
{
    public function __construct(
        private readonly TenantUserResolver $tenantUsers,
        private readonly SetUserStatusAction $setStatus,
    ) {}

    public function __invoke(Request $request, string $user): RedirectResponse
    {
        $target = $this->tenantUsers->resolveAuthorized($request->user(), $user, 'block');

        try {
            $this->setStatus->execute($target, $request->all());
        } catch (SelfLockoutException $exception) {
            throw ValidationException::withMessages(['status' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => ToastType::Success->value, 'message' => __('i18n.backend.http.controllers.users.user_statuses_controller.status_changed')]);

        return to_route('engine.users.index');
    }
}
