<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\RecordWatcher;
use App\Models\User;
use App\Support\Users\UserOptionPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RecordWatcher
 */
class WatcherResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var RecordWatcher $watcher */
        $watcher = $this->resource;

        return [
            'id' => $watcher->id,
            'source' => $watcher->source->value,
            'user' => $this->userRef($watcher->user),
            'createdAt' => $watcher->created_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, string>|null
     */
    private function userRef(?User $user): ?array
    {
        if (!$user instanceof User) {
            return null;
        }

        return [
            'id' => (string) $user->getKey(),
            'label' => app(UserOptionPresenter::class)->label($user),
        ];
    }
}
