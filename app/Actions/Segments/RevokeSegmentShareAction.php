<?php

declare(strict_types=1);

namespace App\Actions\Segments;

use App\Models\SegmentShare;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class RevokeSegmentShareAction
{
    public function execute(User $actor, SegmentShare $share): void
    {
        Gate::forUser($actor)->authorize('share', $share->segment);

        $share->delete();
    }
}
