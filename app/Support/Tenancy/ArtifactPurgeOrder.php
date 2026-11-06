<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

class ArtifactPurgeOrder
{
    /**
     * @var list<string>
     */
    private array $accessCarryingTables = [
        'users',
        'teams',
        'team_user',
        'sessions',
        'personal_access_tokens',
        'password_reset_tokens',
    ];

    /**
     * @return list<array{table: string, via?: array{column: string, source: string, source_column?: string}}>
     */
    public function full(): array
    {
        /** @var list<array{table: string, via?: array{column: string, source: string, source_column?: string}}> $order */
        $order = config('engine.tenant_content', []);

        return $order;
    }

    /**
     * @return list<array{table: string, via?: array{column: string, source: string, source_column?: string}}>
     */
    public function preservingUserAccess(): array
    {
        return array_values(
            array_filter(
                $this->full(),
                fn (array $entry): bool => !in_array($entry['table'], $this->accessCarryingTables, true),
            ),
        );
    }
}
