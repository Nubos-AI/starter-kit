<?php

declare(strict_types=1);

namespace App\DTOs\Engine;

use App\Enums\Engine\MergeRuleMode;

readonly class MergePlanData
{
    /**
     * @param  list<MergeFieldPlan>  $fields
     * @param  list<MergeTransferPlan>  $transfers
     * @param  list<MergeBlocker>  $blockers
     */
    public function __construct(
        public string $targetId,
        public string $sourceId,
        public int $targetVersion,
        public int $sourceVersion,
        public MergeRuleMode $mode,
        public ?string $ruleId,
        public ?string $ruleName,
        public ?string $denyReason,
        public bool $requiresReason,
        public array $fields,
        public array $transfers,
        public array $blockers,
    ) {}

    public function isMergeable(): bool
    {
        return $this->blockers === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function resultingData(): array
    {
        $data = [];

        foreach ($this->fields as $field) {
            if ($field->resultValue === null && $field->targetValue === null) {
                continue;
            }

            $data[$field->key] = $field->resultValue;
        }

        return $data;
    }
}
