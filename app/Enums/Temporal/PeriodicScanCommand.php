<?php

declare(strict_types=1);

namespace App\Enums\Temporal;

enum PeriodicScanCommand: string
{
    case ScanDueReminders = 'reminders:scan-due';

    case ScanDateTriggerRules = 'notifications:scan-date-triggers';

    case SendNotificationDigests = 'notifications:send-digests';

    case ScanApprovalDeadlines = 'approvals:scan-deadlines';
}
