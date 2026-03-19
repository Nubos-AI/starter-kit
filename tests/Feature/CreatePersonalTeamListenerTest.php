<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a personal team when a user registers', function (): void {
    $user = User::factory()->create();

    event(new Registered($user));

    $personal = $user->personalTeam();
    expect($personal)->not->toBeNull()
        ->and($personal->is_personal)->toBeTrue()
        ->and($personal->owner_id)->toBe($user->id);
});
