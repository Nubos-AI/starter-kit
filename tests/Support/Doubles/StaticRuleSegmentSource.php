<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Models\Segment;
use App\Support\Notifications\RuleSegmentSource;

class StaticRuleSegmentSource extends RuleSegmentSource
{
    /**
     * @var list<string>
     */
    public array $askedFor = [];

    /**
     * @param  array<string, Segment>  $segments
     */
    public function __construct(private readonly array $segments = []) {}

    public function find(string $segmentId): ?Segment
    {
        $this->askedFor[] = $segmentId;

        return $this->segments[$segmentId] ?? null;
    }
}
