<?php

declare(strict_types=1);

namespace App\Support\Teams;

use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\FilterFieldKeyCollector;
use App\Support\Engine\FilterTreeValidator;
use App\Support\Engine\SystemFilterFields;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class TeamAccessRuleGuard
{
    public function __construct(
        private readonly FilterTreeValidator $filterTreeValidator,
        private readonly SystemFilterFields $systemFields,
        private readonly FilterFieldKeyCollector $keyCollector,
    ) {}

    /**
     * @param  array<string, mixed>  $tree
     *
     * @throws ValidationException
     */
    public function assertValid(ObjectType $objectType, array $tree): void
    {
        if ($this->keyCollector->collect($tree) === []) {
            throw ValidationException::withMessages([
                'filter_definition' => __('i18n.backend.support.teams.team_access_rule_guard.an_access_rule_must_contain_at_least_one_condition'),
            ]);
        }

        $this->filterTreeValidator->validate(
            $tree,
            $this->filterableFields($objectType),
            FieldVisibilityResolver::forRequest(),
            $objectType,
        );
    }

    /**
     * @return Collection<int, FieldDefinition>
     */
    public function filterableFields(ObjectType $objectType): Collection
    {
        /** @var Collection<int, FieldDefinition> $fields */
        $fields = FieldDefinition::query()
            ->where('object_type_id', $objectType->getKey())
            ->where('is_filterable', true)
            ->get()
            ->toBase();

        return $fields->concat($this->systemFields->all((string) $objectType->getKey()));
    }
}
