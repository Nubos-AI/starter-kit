<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Models\RelationshipType;
use App\Support\Engine\RelationshipEndpointGuard;
use App\Support\Engine\RelationshipKeyGenerator;
use App\Traits\Engine\ValidatesRelationshipTypeInput;
use Illuminate\Validation\ValidationException;

class CreateRelationshipTypeAction
{
    use ValidatesRelationshipTypeInput;

    public function __construct(
        private readonly RelationshipKeyGenerator $keyGenerator,
        private readonly RelationshipEndpointGuard $endpointGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function execute(array $input): RelationshipType
    {
        $validated = $this->validatedRelationshipTypeInput($input);

        $this->endpointGuard->assertUsable($validated);

        $key = $this->keyGenerator->generate($validated['name']);

        return RelationshipType::query()->create([
            ...$this->relationshipTypeAttributes($validated),
            'key' => $key,
            'inverse_key' => $this->keyGenerator->generate($validated['inverse_name'], [$key]),
            'is_hierarchy' => false,
        ]);
    }
}
