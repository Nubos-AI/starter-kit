<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Models\NotificationInbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

class SnoozeInboxAction
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(NotificationInbox $inbox, array $input): NotificationInbox
    {
        $validated = Validator::make(
            $input,
            ['snoozed_until' => ['required', 'date']]
        )->validate();

        $inbox->update(['snoozed_until' => Carbon::parse($validated['snoozed_until'])]);

        return $inbox;
    }
}
