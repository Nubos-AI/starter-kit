<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenancy;

use App\Actions\Tenancy\UpdateTenantSettingsAction;
use App\Enums\Preferences\PreferenceCategory;
use App\Http\Controllers\Abstracts\Controller;
use App\Support\Preferences\PreferencePolicyResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PreferencePolicyController extends Controller
{
    public function __construct(private readonly PreferencePolicyResolver $policies) {}

    public function edit(Request $request): Response
    {
        $user = $this->actingUser($request);

        return Inertia::render('organisation/PreferencePolicy', [
            'policy' => $this->policies->forUser($user),
            'categories' => $this->categories(),
            'canUpdate' => $user->hasPermission('organisation.update'),
        ]);
    }

    public function update(Request $request, UpdateTenantSettingsAction $action): RedirectResponse
    {
        $user = $this->actingUser($request);

        $action->execute($user->tenant, $request->only('preference_policy'));

        return back();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function categories(): array
    {
        return array_map(
            fn (PreferenceCategory $category): array => [
                'value' => $category->value,
                'label' => $category->label(),
                'areas' => array_column($category->areas(), 'value'),
            ],
            PreferenceCategory::cases(),
        );
    }
}
