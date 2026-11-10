<?php

declare(strict_types=1);

namespace App\Actions\Aging;

use App\Models\AgingRule;
use App\Models\ObjectType;
use App\Support\Aging\AgingRuleValidator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateAgingRuleAction
{
    public function __construct(private readonly AgingRuleValidator $ruleValidator) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function execute(ObjectType $objectType, array $input): AgingRule
    {
        $validated = Validator::make($input, $this->ruleValidator->rules($objectType, null))->validate();

        $this->ruleValidator->assertValid($objectType, $validated, null);

        return AgingRule::query()->create([
            ...$this->ruleValidator->normalize($validated),
            'object_type_id' => $objectType->getKey(),
        ]);
    }
}
