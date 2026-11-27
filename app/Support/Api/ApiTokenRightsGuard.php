<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Enums\Api\ApiAccessLevel;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Authorization\RowAccess\TeamAccessRuleResolver;
use App\Support\Engine\ObjectTypeRegistry;
use Illuminate\Validation\ValidationException;

class ApiTokenRightsGuard
{
    public function __construct(
        private readonly ApiAbilityMap $abilityMap,
        private readonly FieldVisibilityResolver $fieldVisibility,
        private readonly TeamAccessRuleResolver $teamAccessRules,
        private readonly ObjectTypeRegistry $objectTypes,
    ) {}

    /**
     * @param  list<array{objectType: string, levels: list<string>}>  $access
     *
     * @throws ValidationException
     */
    public function assertWithinOwnRights(User $actor, array $access): void
    {
        $errors = [];

        foreach ($access as $index => $entry) {
            foreach ($entry['levels'] as $level) {
                $refusal = $this->refusalFor($actor, $entry['objectType'], ApiAccessLevel::from($level));

                if ($refusal !== null) {
                    $errors["objectTypeAccess.{$index}.levels"] ??= [$refusal];
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function refusalFor(User $actor, string $objectTypeSlug, ApiAccessLevel $level): ?string
    {
        foreach ($this->abilityMap->permissionsFor($objectTypeSlug, $level) as $permission) {
            if (!$actor->hasPermission($permission)) {
                return __('i18n.backend.support.api.api_token_rights_guard.a_token_may_not_grant_more_than_your_own_rights');
            }
        }

        $objectTypeId = (string) $this->objectTypes->bySlug($objectTypeSlug)->getKey();

        if ($this->forbiddenFieldKeys($actor, $objectTypeId, $level) !== []) {
            return __('i18n.backend.support.api.api_token_rights_guard.a_token_may_not_unlock_fields_that_are_hidden_from_you');
        }

        if (in_array($objectTypeId, $this->teamAccessRules->resolveFor($actor)->objectTypeIds(), true)) {
            return __('i18n.backend.support.api.api_token_rights_guard.a_token_may_not_bypass_the_record_restrictions_that_apply_to_you');
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function forbiddenFieldKeys(User $actor, string $objectTypeId, ApiAccessLevel $level): array
    {
        return match ($level) {
            ApiAccessLevel::Read => $this->fieldVisibility->forbiddenReadFieldKeys($actor, $objectTypeId),
            ApiAccessLevel::Write => $this->fieldVisibility->forbiddenWriteFieldKeys($actor, $objectTypeId),
        };
    }
}
