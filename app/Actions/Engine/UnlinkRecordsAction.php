<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Enums\Timeline\RelationTimelineAction;
use App\Exceptions\Timeline\UnknownTimelineSourceException;
use App\Models\RecordLink;
use App\Support\Engine\RollupOwnerStarter;
use App\Support\Timeline\RelationTimelineWriter;
use JsonException;

class UnlinkRecordsAction
{
    public function __construct(
        private readonly RelationTimelineWriter $timeline,
        private readonly RollupOwnerStarter $rollupStarter,
    ) {}

    /**
     * @throws UnknownTimelineSourceException
     * @throws JsonException
     */
    public function execute(RecordLink $link): void
    {
        $link->loadMissing('fromRecord');
        $parent = $link->fromRecord;

        $this->timeline->record($link, RelationTimelineAction::Unlinked);

        $link->delete();

        $this->rollupStarter->startForRecords($parent);
    }
}
