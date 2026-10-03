<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Enums\Teams\TeamAccessRuleInheritance;
use App\Models\ObjectType;
use App\Models\Team;
use App\Models\TeamRecordAccessRule;
use App\Models\User;
use App\Support\Teams\TeamAccessRuleGuard;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UpsertTeamRecordAccessRuleAction
{
    public function __construct(private readonly TeamAccessRuleGuard $guard) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(User $actingUser, Team $team, ObjectType $objectType, array $input): TeamRecordAccessRule
    {
        $validated = Validator::make(
            $input,
            [
                'filter_definition' => ['required', 'array'],
                'is_active' => ['sometimes', 'boolean'],
                'inheritance' => ['sometimes', Rule::enum(TeamAccessRuleInheritance::class)],
            ]
        )->validate();

        $this->guard->assertValid($objectType, $validated['filter_definition']);

        return TeamRecordAccessRule::query()->updateOrCreate(
            [
                'team_id' => $team->getKey(),
                'object_type_id' => $objectType->getKey(),
            ],
            [
                'created_by_id' => $actingUser->getKey(),
                'is_active' => (bool) ($validated['is_active'] ?? true),
                'inheritance' => TeamAccessRuleInheritance::from(
                    (string) ($validated['inheritance'] ?? TeamAccessRuleInheritance::Intersect->value),
                ),
                'filter_definition' => $validated['filter_definition'],
            ],
        );
    }
}
