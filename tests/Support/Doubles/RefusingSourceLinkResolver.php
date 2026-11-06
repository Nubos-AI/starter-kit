<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Contracts\Links\ActivitySourceRuleInterface;
use App\Models\CustomRecord;
use App\Models\User;

class RefusingSourceLinkResolver extends StaticSourceLinkResolver implements ActivitySourceRuleInterface
{
    /**
     * @var list<array{source_id: string, record_id: string|null}>
     */
    public array $activityChecks = [];

    /**
     * @param  list<string>  $accessible
     * @param  array<string, string>  $refusals
     */
    public function __construct(string $sourceKey, array $accessible = [], private readonly array $refusals = [])
    {
        parent::__construct($sourceKey, [], $accessible);
    }

    public function activityRefusal(User $user, string $sourceId, ?CustomRecord $record): ?string
    {
        $this->activityChecks[] = ['source_id' => $sourceId, 'record_id' => $record?->getKey()];

        return $this->refusals[$sourceId] ?? null;
    }
}
