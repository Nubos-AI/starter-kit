<?php

declare(strict_types=1);

namespace App\Support\Authorization\RowAccess;

use App\Enums\Teams\TeamAccessRuleInheritance;
use App\Models\TeamRecordAccessRule;
use App\Models\User;

class TeamAccessRuleResolver
{
    /**
     * @var array<string, EffectiveRecordAccessRules>
     */
    private array $memo = [];

    public function __construct(
        private readonly TeamChainProvider $chainProvider,
        private readonly TeamAccessRuleSource $ruleSource,
    ) {}

    public function resolveFor(User $user): EffectiveRecordAccessRules
    {
        if ($user->isEscalatedAuthority()) {
            return EffectiveRecordAccessRules::unrestricted();
        }

        $chains = $this->chainProvider->chainsFor($user);

        if ($chains === []) {
            return EffectiveRecordAccessRules::unrestricted();
        }

        $key = $this->memoKey($user, $chains);

        return $this->memo[$key] ??= $this->build($chains);
    }

    public function forget(): void
    {
        $this->memo = [];
    }

    /**
     * @param  list<list<string>>  $chains
     */
    private function build(array $chains): EffectiveRecordAccessRules
    {
        $rules = $this->rulesFor($chains);

        if ($rules === []) {
            return EffectiveRecordAccessRules::unrestricted();
        }

        $byObjectType = [];

        foreach (array_keys($rules) as $objectTypeId) {
            $alternatives = [];

            foreach ($chains as $chain) {
                $chainTrees = $this->chainTrees($rules[$objectTypeId], $chain);

                if ($chainTrees === []) {
                    $alternatives = [];

                    break;
                }

                $alternatives[] = $chainTrees;
            }

            if ($alternatives !== []) {
                $byObjectType[$objectTypeId] = $alternatives;
            }
        }

        return new EffectiveRecordAccessRules($byObjectType);
    }

    /**
     * @param  array<string, TeamRecordAccessRule>  $rulesByTeam
     * @param  list<string>  $chain
     * @return list<array<string, mixed>>
     */
    private function chainTrees(array $rulesByTeam, array $chain): array
    {
        $trees = [];

        foreach ($chain as $teamId) {
            $rule = $rulesByTeam[$teamId] ?? null;

            if ($rule === null) {
                continue;
            }

            $trees[] = $rule->filter_definition;

            if ($rule->inheritance === TeamAccessRuleInheritance::Override) {
                break;
            }
        }

        return $trees;
    }

    /**
     * @param  list<list<string>>  $chains
     * @return array<string, array<string, TeamRecordAccessRule>>
     */
    private function rulesFor(array $chains): array
    {
        $teamIds = array_values(array_unique(array_merge(...$chains)));

        $grouped = [];

        foreach ($this->ruleSource->activeFor($teamIds) as $rule) {
            $grouped[$rule->object_type_id][$rule->team_id] = $rule;
        }

        return $grouped;
    }

    /**
     * @param  list<list<string>>  $chains
     */
    private function memoKey(User $user, array $chains): string
    {
        return (string) $user->getKey().'|'.implode(';', array_map(
            static fn (array $chain): string => implode(',', $chain),
            $chains,
        ));
    }
}
