<?php

declare(strict_types=1);

namespace App\Http\Controllers\Users;

use App\Actions\Users\SendUserPasswordResetLinkAction;
use App\Contracts\Temporal\ProcessStarterInterface;
use App\Contracts\Users\ChangeUserPasswordWorkflowInterface;
use App\DTOs\Temporal\ProcessStartData;
use App\DTOs\Users\ChangeUserPasswordData;
use App\Enums\Ui\ToastType;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\User;
use App\Support\Temporal\ProcessFingerprints;
use App\Support\Temporal\ProcessId;
use App\Support\Users\TenantUserResolver;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UserPasswordsController extends Controller
{
    public function __construct(
        private readonly TenantUserResolver $tenantUsers,
        private readonly ProcessStarterInterface $processes,
        private readonly Encrypter $encrypter,
        private readonly SendUserPasswordResetLinkAction $sendResetLink,
        private readonly ProcessFingerprints $fingerprints,
    ) {}

    public function update(Request $request, string $user): RedirectResponse
    {
        $target = $this->target($request, $user, 'updatePassword');

        $tenantId = (string) $target->tenant_id;
        $userId = (string) $target->getKey();
        $input = ChangeUserPasswordData::sealed($this->encrypter, $tenantId, $userId, $request->only(['password', 'password_confirmation']));

        $this->processes->startWithUpdate(
            new ProcessStartData(
                workflow: ChangeUserPasswordWorkflowInterface::class,
                workflowId: ProcessId::forTenant('user-password-change', $tenantId, $userId, $this->fingerprints->of([$input])),
                arguments: [$input],
            ),
            'setPassword',
            [$input],
        );

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
