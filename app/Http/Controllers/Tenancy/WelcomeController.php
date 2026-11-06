<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenancy;

use App\Http\Controllers\Abstracts\Controller;
use App\Models\User;
use App\Support\Tenancy\PublicSiteProviders;
use App\Support\Tenancy\WelcomeTenantResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class WelcomeController extends Controller
{
    public function __construct(
        private readonly WelcomeTenantResolver $tenants,
        private readonly PublicSiteProviders $sites,
    ) {}

    /**
     * @throws ModelNotFoundException
     * @throws NotFoundHttpException
     */
    public function __invoke(Request $request): RedirectResponse|Response
    {
        $address = $this->tenants->resolve($request);
        $user = $request->user();
        $visitor = $user instanceof User ? $user : null;
        $page = $address === null ? null : $this->sites->pageOf($address, $visitor);

        if ($page !== null) {
            return Inertia::render($page->component, $page->props);
        }

        if ($visitor !== null) {
            return $visitor->current_team_id === null
                ? redirect()->route('profile.edit')
                : redirect()->route('dashboard', ['activeTeam' => $visitor->current_team_id]);
        }

        if ($address !== null && ($address->team !== null || $address->path !== '')) {
            throw new NotFoundHttpException;
        }

        return Inertia::render('Welcome', [
            'canRegister' => Features::enabled(Features::registration()),
            'tenant' => $address === null ? null : ['name' => $address->tenant->name],
        ]);
    }
}
