<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use App\Enums\Api\ApiAccessLevel;
use App\Enums\Api\ApiTokenAbility;
use App\Support\Api\ApiAbilityMap;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureRecordAbility
{
    public function __construct(private readonly ApiAbilityMap $abilityMap) {}

    public function handle(Request $request, Closure $next, string $level): Response
    {
        $accessLevel = ApiAccessLevel::from($level);
        $token = $request->user()?->currentAccessToken();

        if (!$token instanceof PersonalAccessToken) {
            throw new AuthenticationException;
        }

        $objectTypeSlug = $request->route('typeSlug');

        $satisfied = $this->abilityMap->satisfies(
            array_values($token->abilities ?? []),
            is_string($objectTypeSlug) ? $objectTypeSlug : null,
            $accessLevel,
        );

        if (!$satisfied) {
            throw new MissingAbilityException($this->expectedAbilities($objectTypeSlug, $accessLevel));
        }

        return $next($request);
    }

    /**
     * @return list<string>
     */
    private function expectedAbilities(mixed $objectTypeSlug, ApiAccessLevel $level): array
    {
        $expected = [ApiTokenAbility::forLevel($level)->value];

        if (is_string($objectTypeSlug)) {
            $expected[] = $this->abilityMap->abilityFor($objectTypeSlug, $level);
        }

        return $expected;
    }
}
