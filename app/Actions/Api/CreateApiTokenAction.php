<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\Enums\Api\ApiAccessLevel;
use App\Enums\Api\ApiTokenAbility;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Api\ApiAbilityMap;
use App\Support\Api\ApiTokenRightsGuard;
use App\Traits\Api\IssuesCreatorBoundTokens;
use App\Traits\Api\ResolvesTokenExpiry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Laravel\Sanctum\NewAccessToken;

class CreateApiTokenAction
{
    use IssuesCreatorBoundTokens;
    use ResolvesTokenExpiry;

    public function __construct(
        private readonly ProvisionServiceUserAction $provisionServiceUser,
        private readonly ApiAbilityMap $abilityMap,
        private readonly ApiTokenRightsGuard $rightsGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(User $actor, Tenant $tenant, array $input): NewAccessToken
    {
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'objectTypeAccess' => ['required', 'array', 'min:1'],
            'objectTypeAccess.*.objectType' => ['required', 'string', Rule::notIn([ApiTokenAbility::scope()]), $this->objectTypeRule($tenant)],
            'objectTypeAccess.*.levels' => ['required', 'array', 'min:1'],
            'objectTypeAccess.*.levels.*' => ['required', Rule::enum(ApiAccessLevel::class)],
            'expiresAt' => ['nullable', 'date', 'after:now'],
        ])->validate();

        /** @var list<array{objectType: string, levels: list<string>}> $access */
        $access = $validated['objectTypeAccess'];

        $this->rightsGuard->assertWithinOwnRights($actor, $access);

        $serviceUser = $this->provisionServiceUser->execute($tenant);

        return DB::transaction(fn (): NewAccessToken => $this->issueToken(
            $serviceUser,
            $actor,
            $validated['name'],
            $this->abilityMap->abilitiesForAccess($access),
            $this->tokenExpiry($validated['expiresAt'] ?? null),
        ));
    }

    private function objectTypeRule(Tenant $tenant): Exists
    {
        return Rule::exists('object_types', 'slug')->where(
            fn (Builder $query): Builder => $query
                ->where('is_system', false)
                ->where('tenant_id', (string) $tenant->getKey()),
        );
    }
}
