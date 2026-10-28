<?php

declare(strict_types=1);

namespace App\Actions\Segments;

use App\Models\Segment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class SetSegmentDefaultAction
{
    public function execute(User $actor, Segment $segment, bool $isDefault): Segment
    {
        Gate::forUser($actor)->authorize('manageDefaults', $segment);

        $segment->is_default = $isDefault;
        $segment->save();

        return $segment;
    }
}
