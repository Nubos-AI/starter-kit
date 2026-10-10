<?php

declare(strict_types=1);

namespace App\Http\Controllers\Users;

use App\Actions\Users\AcceptInvitationAction;
use App\Enums\Ui\ToastType;
use App\Enums\Users\Salutation;
use App\Exceptions\Users\InvitationNotAcceptableException;
use App\Http\Controllers\Abstracts\Controller;
use App\Support\Users\PendingInvitationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class InvitationAcceptanceController extends Controller
{
    public function __construct(
        private readonly PendingInvitationResolver $pendingInvitations,
        private readonly AcceptInvitationAction $acceptInvitation,
    ) {}

    public function show(string $token): InertiaResponse
    {
        $invited = $this->pendingInvitations->resolveOrFail($token);

        return Inertia::render('auth/AcceptInvitation', [
            'token' => $token,
            'email' => $invited->email,
            'salutations' => Salutation::options(),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $invited = $this->pendingInvitations->resolveOrFail($token);

        try {
            $this->acceptInvitation->execute($invited, $request->all());
        } catch (InvitationNotAcceptableException $exception) {
            report($exception);

            abort(404);
        }

        Auth::login($invited);
        $request->session()->regenerate();

        Inertia::flash('toast', ['type' => ToastType::Success->value, 'message' => __('i18n.backend.http.controllers.users.invitation_acceptance_controller.welcome_aboard')]);

        return redirect()->intended(route('home'));
    }
}
