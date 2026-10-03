<?php

declare(strict_types=1);

namespace App\Actions\Aging;

use App\Models\AgingRule;
use App\Models\ObjectType;
use App\Support\Aging\AgingRuleValidator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdateAgingRuleAction
{
    public function __construct(private readonly AgingRuleValidator $ruleValidator) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function execute(AgingRule $rule, array $input): AgingRule
    {
        $objectType = ObjectType::query()->whereKey($rule->object_type_id)->firstOrFail();
        $validated = Validator::make($input, $this->ruleValidator->rules($objectType, $rule))->validate();

        $this->ruleValidator->assertValid($objectType, $validated, $rule);

        $rule->update($this->ruleValidator->normalize($validated));

        return $rule;
    }
}
