<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Models\User;
use App\Support\Authorization\RowAccess\EffectiveRecordAccessRules;
use App\Support\Authorization\RowAccess\TeamAccessRuleResolver;

class FakeTeamAccessRuleResolver extends TeamAccessRuleResolver
{
    /**
     * @var list<string>
     */
    public array $resolvedFor = [];

    public function __construct(private EffectiveRecordAccessRules $rules) {}

    /**
     * @param  array<string, list<list<array<string, mixed>>>>  $byObjectType
     */
    public static function restrictedTo(array $byObjectType): self
    {
        return new self(new EffectiveRecordAccessRules($byObjectType));
    }

    public static function unrestricted(): self
    {
        return new self(EffectiveRecordAccessRules::unrestricted());
    }

    public function resolveFor(User $user): EffectiveRecordAccessRules
    {
        $this->resolvedFor[] = (string) $user->getKey();

        return $this->rules;
    }

    public function forget(): void
    {
        $this->resolvedFor = [];
    }
}
