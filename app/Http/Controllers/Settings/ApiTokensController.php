<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Api\CreatePersonalApiTokenAction;
use App\Actions\Api\RevokeApiTokenAction;
use App\Actions\Api\RotateApiTokenAction;
use App\Enums\Api\ApiTokenAbility;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\User;
use App\Support\Api\ApiTokenPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokensController extends Controller
{
    public function __construct(
        private readonly CreatePersonalApiTokenAction $createPersonalApiToken,
        private readonly RotateApiTokenAction $rotateApiToken,
        private readonly RevokeApiTokenAction $revokeApiToken,
        private readonly ApiTokenPresenter $presenter,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $user = $this->actingUser($request);
        $secret = $request->session()->pull('apiTokenSecret');

        return Inertia::render('settings/ApiTokens', [
            'tokens' => $this->presenter->presentMany($this->ownTokens($user)),
            'abilityOptions' => ApiTokenAbility::options(),
            'secret' => is_string($secret) ? $secret : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->actingUser($request);

        $token = $this->createPersonalApiToken->execute($user, $request->all());

        return to_route('settings.api-tokens.index')->with('apiTokenSecret', $token->plainTextToken);
    }

    public function rotate(Request $request, string $token): RedirectResponse
    {
        $user = $this->actingUser($request);

        $rotated = $this->rotateApiToken->execute($user, $token);

        return to_route('settings.api-tokens.index')->with('apiTokenSecret', $rotated->plainTextToken);
    }

    public function destroy(Request $request, string $token): RedirectResponse
    {
        $user = $this->actingUser($request);

        $this->revokeApiToken->execute($user, $token);

        return to_route('settings.api-tokens.index');
    }

    /**
     * @return Collection<int, PersonalAccessToken>
     */
    private function ownTokens(User $user): Collection
    {
        return $user->tokens()
            ->orderByDesc('created_at')
            ->get();
    }
}
