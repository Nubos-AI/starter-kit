<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Authorization\ScopeResolverInterface;
use App\Models\User;
use App\Services\Authorization\ScopeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ScopeResolverInterface::class, ScopeResolver::class);
    }

    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
    }

    protected function configureAuthorization(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            if ($user->hasRole('nubos:super-admin')) {
                return true;
            }

            $scopes = $this->app->make(ScopeResolverInterface::class)->resolve();

            foreach ($scopes as $scope) {
                if ($user->hasRole('owner', $scope) || $user->hasRole('admin', $scope)) {
                    return true;
                }
            }

            foreach ($scopes as $scope) {
                if ($user->hasPermission($ability, $scope)) {
                    return true;
                }
            }

            return null;
        });
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(
            fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
