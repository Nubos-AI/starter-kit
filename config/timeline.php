<?php

declare(strict_types=1);

return [
    'sources' => [
        'activity' => 'App\Handlers\Timeline\ActivityTimelineSource',
        'field_change' => 'App\Handlers\Timeline\ChangeTimelineSource',
        'reminder' => 'App\Handlers\Timeline\ReminderTimelineSource',
        'file' => 'App\Handlers\Timeline\FileTimelineSource',
        'note' => 'App\Handlers\Timeline\NoteTimelineSource',
        'merge' => 'App\Handlers\Timeline\MergeTimelineSource',
        'relation' => 'App\Handlers\Timeline\RelationTimelineSource',
        'approval' => 'App\Handlers\Timeline\ApprovalTimelineSource',
    ],
];
