<?php

declare(strict_types=1);

namespace App\DTOs\Promotion;

use App\Enums\Promotion\ConflictResolution;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

readonly class ConflictDecision
{
    public function __construct(
        public string $kind,
        public string $key,
        public ConflictResolution $resolution,
        public string $decidedById,
        public CarbonImmutable $decidedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $decision
     */
    public static function fromArray(array $decision): self
    {
        $kind = (string) ($decision['kind'] ?? '');
        $key = (string) ($decision['key'] ?? '');
        $decidedById = (string) ($decision['decided_by_id'] ?? '');
        $decidedAt = (string) ($decision['decided_at'] ?? '');

        if ($kind === '') {
            throw new InvalidArgumentException(__('i18n.backend.dtos.promotion.conflict_decision.a_conflict_decision_array_needs_the_artifact_kind'));
        }

        if ($key === '') {
            throw new InvalidArgumentException(__('i18n.backend.dtos.promotion.conflict_decision.a_conflict_decision_array_needs_the_business_key_of'));
        }

        if ($decidedById === '') {
            throw new InvalidArgumentException(__('i18n.backend.dtos.promotion.conflict_decision.a_conflict_decision_array_needs_the_identifier_of_the'));
        }

        if ($decidedAt === '') {
            throw new InvalidArgumentException(__('i18n.backend.dtos.promotion.conflict_decision.a_conflict_decision_array_needs_the_time_the_decision'));
        }

        return new self(
            kind: $kind,
            key: $key,
            resolution: ConflictResolution::from((string) ($decision['resolution'] ?? '')),
            decidedById: $decidedById,
            decidedAt: CarbonImmutable::parse($decidedAt),
        );
    }

    /**
     * @return array{kind: string, key: string, resolution: string, decided_by_id: string, decided_at: string}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'key' => $this->key,
            'resolution' => $this->resolution->value,
            'decided_by_id' => $this->decidedById,
            'decided_at' => $this->decidedAt->toIso8601String(),
        ];
    }
}
