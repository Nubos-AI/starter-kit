<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Models\MergeRule;
use App\Models\ObjectType;
use App\Support\Engine\MergeRuleValidator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdateMergeRuleAction
{
    public function __construct(private readonly MergeRuleValidator $ruleValidator) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function execute(MergeRule $rule, array $input): MergeRule
    {
        $objectType = ObjectType::query()->whereKey($rule->object_type_id)->firstOrFail();

        $validated = Validator::make($input, $this->ruleValidator->rules($objectType, $rule))->validate();

        $this->ruleValidator->assertValid($objectType, $validated, $rule);

        $rule->update($this->ruleValidator->normalize($validated));

        return $rule;
    }
}
