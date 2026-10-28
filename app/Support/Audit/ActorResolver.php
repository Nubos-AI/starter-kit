<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Enums\Audit\ActorType;
use Illuminate\Support\Facades\Auth;

class ActorResolver
{
    /**
     * @return array{0: string|null, 1: string|null} id and actor type
     */
    public function resolve(): array
    {
        if (app()->bound('current_automation_actor')) {
            /** @var array{id: string, type: ActorType} $marker */
            $marker = app('current_automation_actor');

            return [$marker['id'], $marker['type']->value];
        }

        $actorId = Auth::id();

        return $actorId !== null
            ? [(string) $actorId, ActorType::User->value]
            : [null, null];
    }
}
