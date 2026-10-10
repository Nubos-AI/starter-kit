<?php

declare(strict_types=1);

namespace App\Enums\Engine;

enum MergeTransferCategory: string
{
    case Links = 'links';

    case Attachments = 'attachments';

    case Notes = 'notes';

    case Watchers = 'watchers';

    case Reminders = 'reminders';

    case Timeline = 'timeline';

    case Audit = 'audit';

    case AutomationRuns = 'automation_runs';

    case AgingStates = 'aging_states';

    public function defaultPolicy(): MergeTransferPolicy
    {
        return match ($this) {
            self::Links, self::Attachments, self::Notes, self::Watchers, self::Reminders => MergeTransferPolicy::Move,
            self::Timeline, self::Audit, self::AutomationRuns => MergeTransferPolicy::Keep,
            self::AgingStates => MergeTransferPolicy::Discard,
        };
    }

    /**
     * @return list<MergeTransferPolicy>
     */
    public function allowedPolicies(): array
    {
        return match ($this) {
            self::Audit => [MergeTransferPolicy::Keep],
            self::Timeline => [MergeTransferPolicy::Keep, MergeTransferPolicy::Discard],
            default => MergeTransferPolicy::cases(),
        };
    }

    public function allows(MergeTransferPolicy $policy): bool
    {
        return in_array($policy, $this->allowedPolicies(), true);
    }
}
