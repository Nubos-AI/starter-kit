<?php

declare(strict_types=1);

namespace App\DTOs\Engine;

use App\Enums\Engine\RollupScope;

readonly class RollupConfiguration
{
    /**
     * @param  list<string>  $filterFieldKeys
     */
    public function __construct(
        public RollupScope $scope,
        public ?string $relationshipTypeId,
        public string $targetObjectTypeId,
        public ?string $sourceFieldKey,
        public array $filterFieldKeys,
    ) {}

    /**
     * @return list<string>
     */
    public function dependsOnFieldKeys(): array
    {
        $keys = $this->sourceFieldKey === null
            ? $this->filterFieldKeys
            : [$this->sourceFieldKey, ...$this->filterFieldKeys];

        return array_values(array_unique($keys));
    }
}
