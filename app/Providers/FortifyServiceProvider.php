<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Users\CreateUserAction;
use App\Actions\Users\ResetUserPasswordAction;
use App\Enums\Sso\SsoFailure;
use App\Enums\Users\Salutation;
use App\Http\Responses\Auth\FailedPasswordResetLinkRequestResponse;
use App\Http\Responses\Auth\LoginResponse;
use App\Http\Responses\Auth\LogoutResponse;
use App\Models\User;
use App\Support\Sso\SsoEnforcementGate;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse as FailedPasswordResetLinkRequestResponseContract;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Passkeys;

class FortifyServiceProvider extends ServiceProvider
{
    private ?string $decoyHash = null;

    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(LogoutResponseContract::class, LogoutResponse::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponseContract::class, FailedPasswordResetLinkRequestResponse::class);
    }

    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->configurePasswordReset();
        $this->configureEmailVerification();
    }

    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPasswordAction::class);
        Fortify::createUsersUsing(CreateUserAction::class);
        Fortify::authenticateUsing($this->authenticator());
        Passkeys::authorizeLoginUsing(function (Request $request, mixed $user): bool {
            if (!$user instanceof User || $user->is_service || !$user->status->canAuthenticate()) {
                return false;
            }

            $this->refuseEnforcedLocalLogin($user, 'credential');

            return true;
        });
    }

    /**
     * @return callable(Request): ?User
     */
    private function authenticator(): callable
    {
        return function (Request $request): ?User {
            $user = User::query()
                ->where(Fortify::username(), (string) $request->input(Fortify::username()))
                ->where('is_service', false)
                ->first();

            $secret = $user instanceof User && $user->password !== null
                ? $user->password
                : $this->decoyHash();
            $matches = Hash::check((string) $request->input('password'), $secret);

            if (!$user instanceof User || !$user->status->canAuthenticate() || !$matches) {
                return null;
            }

            $this->refuseEnforcedLocalLogin($user, Fortify::username());

            return $user;
        };
    }

    /**
     * @throws ValidationException
     */
    private function refuseEnforcedLocalLogin(User $user, string $field): void
    {
        if ($this->app->make(SsoEnforcementGate::class)->blocksLocalLogin($user)) {
            throw ValidationException::withMessages([$field => SsoFailure::LocalLoginEnforced->label()]);
        }
    }

    private function decoyHash(): string
    {
        return $this->decoyHash ??= Hash::make(Str::random(64));
    }

    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
            'ssoError' => $request->session()->get('ssoError'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/Register', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'salutations' => Salutation::options(),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('sso', function (Request $request) {
            return Limit::perMinute((int) config('sso.oidc.start_attempts_per_minute'))->by((string) $request->ip());
        });

        RateLimiter::for('registration', function (Request $request) {
            return Limit::perMinute(5)->by((string) $request->ip());
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return [
                Limit::perMinute(10)->by(
                    ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
                ),
                Limit::perMinute(10)->by('ip:'.$request->ip()),
            ];
        });
    }

    private function configurePasswordReset(): void
    {
        ResetPassword::createUrlUsing(fn (User $notifiable, string $token): string => rtrim((string) config('app.url'), '/').route('password.reset', [
            'token' => $token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], absolute: false));

        $this->app->booted(fn () => $this->app->booted(function (): void {
            foreach (['password.email', 'password.update', 'password.confirm.store'] as $name) {
                Route::getRoutes()->getByName($name)?->middleware('throttle:6,1');
            }

            Route::getRoutes()->getByName('register.store')?->middleware('throttle:registration');
        }));
    }

    private function configureEmailVerification(): void
    {
        VerifyEmail::createUrlUsing(fn (User $notifiable): string => rtrim((string) config('app.url'), '/').URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes((int) config('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ],
            absolute: false,
        ));

        $this->app->booted(fn () => $this->app->booted(function (): void {
            $route = Route::getRoutes()->getByName('verification.verify');

            if ($route === null) {
                return;
            }

            $route->setAction([
                ...$route->getAction(),
                'middleware' => array_map(
                    static fn (mixed $middleware): mixed => $middleware === 'signed' ? 'signed:relative' : $middleware,
                    (array) $route->getAction('middleware'),
                ),
            ]);
        }));
    }
}
