<?php

declare(strict_types=1);

namespace App\DTOs\Governance;

use App\Enums\Governance\CandidateSource;

readonly class CandidateCircle
{
    /**
     * @param  list<CandidateSource>  $sources
     * @param  list<string>  $roleIds
     * @param  list<string>  $teamIds
     * @param  list<string>  $userIds
     */
    public function __construct(
        public array $sources,
        public array $roleIds,
        public array $teamIds,
        public bool $includeRecordTeam,
        public ?string $fieldKey,
        public array $userIds,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            sources: self::sources($config['sources'] ?? []),
            roleIds: self::identifiers($config['role_ids'] ?? []),
            teamIds: self::identifiers($config['team_ids'] ?? []),
            includeRecordTeam: (bool) ($config['include_record_team'] ?? false),
            fieldKey: self::fieldKey($config['field_key'] ?? null),
            userIds: self::identifiers($config['user_ids'] ?? []),
        );
    }

    /**
     * @return array{sources: list<string>, role_ids: list<string>, team_ids: list<string>, include_record_team: bool, field_key: string|null, user_ids: list<string>}
     */
    public function toArray(): array
    {
        return [
            'sources' => array_map(
                static fn (CandidateSource $source): string => $source->value,
                $this->sources,
            ),
            'role_ids' => $this->roleIds,
            'team_ids' => $this->teamIds,
            'include_record_team' => $this->includeRecordTeam,
            'field_key' => $this->fieldKey,
            'user_ids' => $this->userIds,
        ];
    }

    public function hasSource(CandidateSource $source): bool
    {
        return in_array($source, $this->sources, true);
    }

    public function dependsOnRecord(): bool
    {
        if ($this->hasSource(CandidateSource::Field) && $this->fieldKey !== null) {
            return true;
        }

        return $this->hasSource(CandidateSource::Team) && $this->includeRecordTeam;
    }

    /**
     * @return list<CandidateSource>
     */
    private static function sources(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $sources = [];

        foreach ($value as $source) {
            $resolved = is_string($source) ? CandidateSource::tryFrom($source) : null;

            if ($resolved instanceof CandidateSource && !in_array($resolved, $sources, true)) {
                $sources[] = $resolved;
            }
        }

        return $sources;
    }

    /**
     * @return list<string>
     */
    private static function identifiers(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $identifiers = [];

        foreach ($value as $identifier) {
            if (!is_string($identifier) && !is_int($identifier)) {
                continue;
            }

            $identifier = (string) $identifier;

            if ($identifier !== '' && !in_array($identifier, $identifiers, true)) {
                $identifiers[] = $identifier;
            }
        }

        return $identifiers;
    }

    private static function fieldKey(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
