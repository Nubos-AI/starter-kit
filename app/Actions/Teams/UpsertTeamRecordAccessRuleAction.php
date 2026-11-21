<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Enums\Teams\TeamAccessRuleInheritance;
use App\Models\ObjectType;
use App\Models\Team;
use App\Models\TeamRecordAccessRule;
use App\Models\User;
use App\Support\Teams\TeamAccessRuleGuard;
use Illuminate\Support\Arr;
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

        $rule = TeamRecordAccessRule::query()->firstOrNew([
            'team_id' => $team->getKey(),
            'object_type_id' => $objectType->getKey(),
        ]);

        if (!$rule->exists) {
            $rule->fill([
                'created_by_id' => $actingUser->getKey(),
                'is_active' => true,
                'inheritance' => TeamAccessRuleInheritance::Intersect,
            ]);
        }

        $rule->fill(Arr::only($validated, ['is_active', 'inheritance', 'filter_definition']));
        $rule->save();

        return $rule;
    }
}
