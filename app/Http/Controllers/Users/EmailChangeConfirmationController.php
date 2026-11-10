<?php

declare(strict_types=1);

namespace App\Http\Controllers\Users;

use App\Actions\Users\ConfirmEmailChangeAction;
use App\Enums\Ui\ToastType;
use App\Exceptions\Users\EmailChangeNotConfirmableException;
use App\Http\Controllers\Abstracts\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class EmailChangeConfirmationController extends Controller
{
    public function __construct(private readonly ConfirmEmailChangeAction $confirmEmailChange) {}

    public function show(Request $request, string $token): InertiaResponse
    {
        $user = $this->actingUser($request);

        try {
            $pendingEmail = $this->confirmEmailChange->openRequestOf($user, $token);
        } catch (EmailChangeNotConfirmableException $exception) {
            Log::info('An email address change confirmation page was opened without an open request.', ['tenant_id' => $user->tenant_id, 'user_id' => $user->getKey(), 'reason' => $exception->getMessage()]);

            abort(404);
        }

        return Inertia::render('auth/ConfirmEmailChange', [
            'token' => $token,
            'email' => $pendingEmail,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $user = $this->actingUser($request);

        try {
            $this->confirmEmailChange->execute($user, $token);
        } catch (EmailChangeNotConfirmableException $exception) {
            Log::info('An email address change confirmation was submitted without an open request.', ['tenant_id' => $user->tenant_id, 'user_id' => $user->getKey(), 'reason' => $exception->getMessage()]);

            abort(404);
        }

        Inertia::flash('toast', ['type' => ToastType::Success->value, 'message' => __('i18n.backend.http.controllers.users.email_change_confirmation_controller.email_address_changed')]);

        return to_route('profile.edit');
    }
}
