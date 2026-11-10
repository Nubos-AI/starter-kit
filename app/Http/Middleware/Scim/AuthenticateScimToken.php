<?php

declare(strict_types=1);

namespace App\Http\Middleware\Scim;

use App\Actions\Scim\RecordScimTokenUseAction;
use App\Enums\Audit\ActorType;
use App\Exceptions\Scim\ScimException;
use App\Models\ScimToken;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Scim\ScimAccessGuard;
use App\Support\Tenancy\TenantBinder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateScimToken
{
    public function __construct(
        private readonly TenantBinder $tenants,
        private readonly RecordScimTokenUseAction $recordUse,
        private readonly ScimAccessGuard $accessGuard,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws ScimException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if ($bearer === null || !str_starts_with($bearer, (string) config('sso.scim.token_prefix'))) {
            throw ScimException::unauthorized();
        }

        $token = ScimToken::withoutTenantScope()->where('token_hash', hash('sha256', $bearer))->first();
        $tenant = $token instanceof ScimToken ? Tenant::query()->find($token->tenant_id) : null;

        if (!$token instanceof ScimToken || !$tenant instanceof Tenant) {
            Log::warning('A scim request carried an unknown token.', ['ip' => $request->ip()]);

            throw ScimException::unauthorized();
        }

        $this->recordUse->execute($token);
        $request->attributes->set('scim_token', $token);

        return $this->tenants->runWith($tenant, function () use ($request, $next, $token): Response {
            $creator = $this->accessGuard->entitledCreator($token);

            if (!$creator instanceof User) {
                Log::warning('A scim request carried a token whose creator is no longer entitled.', ['tenant_id' => $token->tenant_id, 'token_id' => $token->getKey()]);

                throw ScimException::tokenCreatorNotEntitled();
            }

            $request->setUserResolver(static fn (): User => $creator);
            app()->instance('current_automation_actor', ['id' => (string) $token->getKey(), 'type' => ActorType::System]);

            try {
                return $next($request);
            } finally {
                app()->forgetInstance('current_automation_actor');
            }
        });
    }
}
