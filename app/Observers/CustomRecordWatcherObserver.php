<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\CustomRecord;
use App\Support\Watchers\WatcherAutoSubscriber;

class CustomRecordWatcherObserver
{
    public function __construct(private readonly WatcherAutoSubscriber $watcherAutoSubscriber) {}

    public function created(CustomRecord $record): void
    {
        $this->watcherAutoSubscriber->onRecordCreated($record);
    }
}
