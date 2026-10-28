<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Users\CreateUserAction;
use App\Actions\Users\ResetUserPasswordAction;
use App\Enums\Users\Salutation;
use App\Http\Responses\Auth\LoginResponse;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Passkeys;

class FortifyServiceProvider extends ServiceProvider
{
    private ?string $decoyHash = null;

    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
    }

    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPasswordAction::class);
        Fortify::createUsersUsing(CreateUserAction::class);
        Fortify::authenticateUsing($this->authenticator());
        Passkeys::authorizeLoginUsing(
            static fn (Request $request, mixed $user): bool => $user instanceof User
                && !$user->is_service
                && $user->status->canAuthenticate(),
        );
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

            if (!$user instanceof User || !$user->status->canAuthenticate()) {
                return null;
            }

            return $matches ? $user : null;
        };
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

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
