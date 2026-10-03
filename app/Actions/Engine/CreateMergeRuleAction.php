<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Models\MergeRule;
use App\Models\ObjectType;
use App\Support\Engine\MergeRuleValidator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateMergeRuleAction
{
    public function __construct(private readonly MergeRuleValidator $ruleValidator) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function execute(ObjectType $objectType, array $input): MergeRule
    {
        $validated = Validator::make($input, $this->ruleValidator->rules($objectType, null))->validate();

        $this->ruleValidator->assertValid($objectType, $validated, null);

        return MergeRule::query()->create([
            ...$this->ruleValidator->normalize($validated),
            'object_type_id' => $objectType->getKey(),
        ]);
    }
}
