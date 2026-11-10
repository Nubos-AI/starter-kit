<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sso;

use App\Actions\Sso\CompleteSsoLoginAction;
use App\Actions\Sso\CompleteSsoLoginTestAction;
use App\Actions\Sso\StartSsoLoginAction;
use App\DTOs\Sso\PendingSsoLogin;
use App\Enums\Sso\SsoLoginPurpose;
use App\Enums\Ui\ToastType;
use App\Exceptions\Sso\SsoLoginException;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\User;
use App\Support\Sso\OidcAuthorizationFlow;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse;
use Symfony\Component\HttpFoundation\Response;

class SsoLoginController extends Controller
{
    public function __construct(
        private readonly StartSsoLoginAction $startLogin,
        private readonly CompleteSsoLoginAction $completeLogin,
        private readonly CompleteSsoLoginTestAction $completeLoginTest,
        private readonly RedirectIfAuthenticated $guestOnly,
        private readonly StatefulGuard $guard,
        private readonly LoginResponse $loginResponse,
    ) {}

    /**
     * @throws ValidationException
     */
    public function redirect(Request $request): Response
    {
        $url = $this->startLogin->execute($request->only('email'), $request->session());

        return Inertia::location($url);
    }

    public function callback(Request $request): Response
    {
        $pending = PendingSsoLogin::fromSession($request->session()->get(OidcAuthorizationFlow::$sessionKey));

        if ($pending?->purpose === SsoLoginPurpose::ConnectionTest) {
            return $this->finishLoginTest($request, $pending);
        }

        return $this->guestOnly->handle($request, fn (Request $request): Response => $this->finishLogin($request));
    }

    private function finishLogin(Request $request): Response
    {
        try {
            $user = $this->completeLogin->execute($request->query(), $request->session());
        } catch (SsoLoginException $exception) {
            Log::warning('A single sign-on was refused.', ['reason' => $exception->failure->value]);

            $request->session()->flash('ssoError', $exception->failure->label());

            return to_route('login');
        }

        $this->guard->login($user);
        $request->session()->regenerate();

        return $this->loginResponse->toResponse($request);
    }

    private function finishLoginTest(Request $request, PendingSsoLogin $pending): Response
    {
        $actingUser = $request->user();

        try {
            $this->completeLoginTest->execute($request->query(), $request->session(), $actingUser instanceof User ? $actingUser : null);

            Inertia::flash('toast', ['type' => ToastType::Success->value, 'message' => __('i18n.backend.http.controllers.sso.sso_login_controller.login_test_succeeded')]);
        } catch (SsoLoginException $exception) {
            Log::warning('A single sign-on login test was refused.', ['tenant_id' => $pending->tenantId, 'connection_id' => $pending->connectionId, 'reason' => $exception->failure->value]);

            Inertia::flash('toast', [
                'type' => ToastType::Error->value,
                'message' => __('i18n.backend.http.controllers.sso.sso_login_controller.login_test_failed', ['reason' => $exception->failure->label()]),
            ]);
        }

        return redirect()->to($pending->returnUrl ?? route('login'));
    }
}
