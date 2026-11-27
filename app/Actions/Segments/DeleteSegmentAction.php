<?php

declare(strict_types=1);

namespace App\Actions\Segments;

use App\Models\Segment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class DeleteSegmentAction
{
    public function execute(User $actor, Segment $segment): void
    {
        Gate::forUser($actor)->authorize('delete', $segment);

        $segment->delete();
    }
}
