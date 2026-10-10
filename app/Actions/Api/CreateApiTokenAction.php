<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\Enums\Api\ApiAccessLevel;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Api\ApiAbilityMap;
use App\Traits\Api\ResolvesTokenExpiry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

class CreateApiTokenAction
{
    use ResolvesTokenExpiry;

    public function __construct(
        private readonly ProvisionServiceUserAction $provisionServiceUser,
        private readonly ApiAbilityMap $abilityMap,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(User $actor, Tenant $tenant, array $input): NewAccessToken
    {
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'objectTypeAccess' => ['required', 'array', 'min:1'],
            'objectTypeAccess.*.objectType' => ['required', 'string', $this->objectTypeRule($tenant)],
            'objectTypeAccess.*.levels' => ['required', 'array', 'min:1'],
            'objectTypeAccess.*.levels.*' => ['required', Rule::enum(ApiAccessLevel::class)],
            'expiresAt' => ['nullable', 'date', 'after:now'],
        ])->validate();

        /** @var list<array{objectType: string, levels: list<string>}> $access */
        $access = $validated['objectTypeAccess'];

        $this->assertWithinOwnRights($actor, $access);

        return $this->provisionServiceUser->execute($tenant)->createToken(
            $validated['name'],
            $this->abilityMap->abilitiesForAccess($access),
            $this->tokenExpiry($validated['expiresAt'] ?? null),
        );
    }

    /**
     * @param  list<array{objectType: string, levels: list<string>}>  $access
     *
     * @throws ValidationException
     */
    private function assertWithinOwnRights(User $actor, array $access): void
    {
        $errors = [];

        foreach ($access as $index => $entry) {
            foreach ($entry['levels'] as $level) {
                $permissions = $this->abilityMap->permissionsFor($entry['objectType'], ApiAccessLevel::from($level));

                foreach ($permissions as $permission) {
                    if (!$actor->hasPermission($permission)) {
                        $errors["objectTypeAccess.{$index}.levels"] = [
                            __('i18n.backend.actions.api.create_api_token_action.a_token_may_not_grant_more_than_your_own_rights'),
                        ];
                    }
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
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
