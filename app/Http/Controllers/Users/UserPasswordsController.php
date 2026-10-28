<?php

declare(strict_types=1);

namespace App\Http\Controllers\Users;

use App\Actions\Users\SendUserPasswordResetLinkAction;
use App\Actions\Users\SetUserPasswordAction;
use App\Enums\Ui\ToastType;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\User;
use App\Support\Users\TenantUserResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UserPasswordsController extends Controller
{
    public function __construct(
        private readonly TenantUserResolver $tenantUsers,
        private readonly SetUserPasswordAction $setPassword,
        private readonly SendUserPasswordResetLinkAction $sendResetLink,
    ) {}

    public function update(Request $request, string $user): RedirectResponse
    {
        $target = $this->target($request, $user, 'updatePassword');

        $this->setPassword->execute($target, $request->all());

        Inertia::flash('toast', ['type' => ToastType::Success->value, 'message' => __('i18n.backend.http.controllers.users.user_passwords_controller.password_set')]);

        return to_route('engine.users.edit', ['user' => $target->getKey()]);
    }

    public function sendResetLink(Request $request, string $user): RedirectResponse
    {
        $target = $this->target($request, $user, 'updatePassword');

        $this->sendResetLink->execute($target);

        Inertia::flash('toast', ['type' => ToastType::Success->value, 'message' => __('i18n.backend.http.controllers.users.user_passwords_controller.reset_link_sent')]);

        return to_route('engine.users.edit', ['user' => $target->getKey()]);
    }

    private function target(Request $request, string $userId, string $ability): User
    {
        return $this->tenantUsers->resolveAuthorized($request->user(), $userId, $ability);
    }
}
