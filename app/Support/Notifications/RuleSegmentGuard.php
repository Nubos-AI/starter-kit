<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Models\Segment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class RuleSegmentGuard
{
    public function __construct(private readonly RuleSegmentSource $segmentSource) {}

    /**
     * @throws ValidationException
     */
    public function assertVisible(User $user, mixed $segmentId): void
    {
        if ($segmentId === null) {
            return;
        }

        $segment = is_string($segmentId)
            ? $this->segmentSource->find($segmentId)
            : null;

        if ($segment instanceof Segment && $user->can('view', $segment)) {
            return;
        }

        throw ValidationException::withMessages([
            'segment_id' => __('validation.exists', ['attribute' => 'segment id']),
        ]);
    }
}
