<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class ResolveApiContext
{
    public function __construct(private readonly AuthFactory $auth) {}

    /**
     * @throws AuthenticationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->is_service) {
            $user = $this->actAsCreator($request, $user, $request->user()?->currentAccessToken());
        }

        if ($user instanceof User && !$user->status->canAuthenticate()) {
            throw new AuthenticationException;
        }

        app()->forgetInstance('current_tenant');

        return $next($request);
    }

    /**
     * @throws AuthenticationException
     */
    private function actAsCreator(Request $request, User $serviceUser, mixed $token): User
    {
        $creatorId = $token instanceof PersonalAccessToken ? $token->getAttribute('creator_id') : null;

        $creator = is_string($creatorId)
            ? User::query()
                ->where('tenant_id', $serviceUser->tenant_id)
                ->where('is_service', false)
                ->whereKey($creatorId)
                ->first()
            : null;

        if (!$token instanceof PersonalAccessToken || !$creator instanceof User) {
            Log::warning('Rejected a service api token that is not bound to an existing creator.', [
                'service_user_id' => $serviceUser->getKey(),
                'token_id' => $token instanceof PersonalAccessToken ? $token->getKey() : null,
                'creator_id' => $creatorId,
            ]);

            throw new AuthenticationException;
        }

        $creator->withAccessToken($token);
        $this->auth->guard()->setUser($creator);
        $request->setUserResolver(static fn (): User => $creator);

        return $creator;
    }
}
