<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Enums\Audit\ActorType;
use App\Enums\Timeline\TimelineChannel;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

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

    public function apiTokenId(?string $actorType): ?int
    {
        if ($actorType !== ActorType::User->value) {
            return null;
        }

        $user = Auth::user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        return $token instanceof PersonalAccessToken ? (int) $token->getKey() : null;
    }

    public function channel(?string $actorType): TimelineChannel
    {
        if ($actorType === ActorType::Automation->value) {
            return TimelineChannel::Automation;
        }

        if ($actorType !== ActorType::User->value) {
            return TimelineChannel::System;
        }

        return $this->apiTokenId($actorType) === null ? TimelineChannel::Web : TimelineChannel::Api;
    }
}
