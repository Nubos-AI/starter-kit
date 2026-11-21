<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenancy;

use App\Actions\Tenancy\UpdateTenantDomainAction;
use App\Actions\Tenancy\VerifyTenantDomainAction;
use App\Enums\Ui\ToastType;
use App\Http\Controllers\Abstracts\Controller;
use App\Support\Authorization\CurrentTeamResolver;
use App\Support\Tenancy\OwnDomains;
use App\Support\Tenancy\WelcomeTenantResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TenantWelcomePageController extends Controller
{
    public function __construct(
        private readonly WelcomeTenantResolver $welcome,
        private readonly CurrentTeamResolver $teams,
        private readonly UpdateTenantDomainAction $updateDomain,
        private readonly VerifyTenantDomainAction $verifyDomain,
        private readonly OwnDomains $ownDomains,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function edit(Request $request): Response
    {
        $user = $this->actingUser($request);
        $tenant = $user->tenant;
        $team = $this->teams->resolve($user);

        return Inertia::render('organisation/WelcomePage', [
            'address' => $this->welcome->addressOf($tenant),
            'team' => $team === null ? null : [
                'name' => $team->name,
                'address' => $this->welcome->addressOf($tenant, $team),
            ],
            'cnameTarget' => $this->welcome->subdomainOf($tenant),
            'domain' => $tenant->domain,
            'domainVerifiedAt' => $tenant->domain_verified_at?->toIso8601String(),
            'domainProof' => $this->ownDomains->proofOf((string) $tenant->getKey(), $tenant->domain),
            'canUpdate' => $user->hasPermission('organisation.update'),
        ]);
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $this->actingUser($request);

        $this->updateDomain->execute($user->tenant, $request->only('domain'));

        Inertia::flash('toast', ['type' => ToastType::Success->value, 'message' => __('i18n.backend.http.controllers.tenancy.tenant_welcome_page_controller.saved')]);

        return back();
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function verify(Request $request): RedirectResponse
    {
        $user = $this->actingUser($request);

        $this->verifyDomain->execute($user->tenant);

        Inertia::flash('toast', ['type' => ToastType::Success->value, 'message' => __('i18n.backend.http.controllers.tenancy.tenant_welcome_page_controller.verified')]);

        return back();
    }
}
