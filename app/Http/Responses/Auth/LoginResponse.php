<?php

declare(strict_types=1);

namespace App\Http\Responses\Auth;

use App\Enums\Preferences\GlobalPreference;
use App\Enums\Preferences\PreferenceScope;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Authorization\CurrentTeamResolver;
use App\Support\Preferences\UserPreferenceResolver;
use App\Support\Tenancy\TenantBinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class LoginResponse implements LoginResponseContract
{
    public function __construct(
        private readonly UserPreferenceResolver $preferences,
        private readonly CurrentTeamResolver $currentTeamResolver,
        private readonly TenantBinder $tenants,
    ) {}

    public function toResponse($request): Response
    {
        $target = $this->target($request);

        return $request->wantsJson()
            ? new JsonResponse('', 204)
            : new RedirectResponse($request->session()->pull('url.intended', $target));
    }

    private function target(Request $request): string
    {
        $user = $request->user();

        if (!$user instanceof User || $user->tenant_id === null) {
            return config('fortify.home');
        }

        return $this->tenants->runIfKnown(
            $user->tenant_id,
            fn (): string => $this->targetWithinTenant($user),
        ) ?? config('fortify.home');
    }

    private function targetWithinTenant(User $user): string
    {
        $teamId = $this->currentTeamResolver->resolveKey($user);
        if ($teamId === null) {
            return route('profile.edit', [], false);
        }
        $objectType = $this->startObjectType($user);

        if (!$objectType instanceof ObjectType) {
            return route('dashboard', ['activeTeam' => $teamId], false);
        }

        $segment = '/'.$teamId;

        return "{$segment}/records/{$objectType->slug}";
    }

    private function startObjectType(User $user): ?ObjectType
    {
        $document = $this->preferences->document($user);
        $startId = $document[PreferenceScope::Settings->value][GlobalPreference::StartObjectTypeId->value] ?? null;

        if (!is_string($startId) || $startId === '') {
            return null;
        }

        $objectType = ObjectType::query()->whereKey($startId)->first();

        return $objectType instanceof ObjectType && $user->hasPermission("{$objectType->slug}.view")
            ? $objectType
            : null;
    }
}
