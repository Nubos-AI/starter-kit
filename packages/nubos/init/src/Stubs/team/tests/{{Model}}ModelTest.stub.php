<?php

declare(strict_types=1);

use App\Actions\{{Models}}\Create{{Model}}Action;
use App\Models\{{Model}};
use App\Models\User;

it('has owner relationship', function (): void {
    $user = User::factory()->create();

    $action = new Create{{Model}}Action();
    ${{model}} = $action->execute($user, ['name' => 'Acme Corp']);

    expect(${{model}}->owner->id)->toBe($user->id);
});

it('has users relationship', function (): void {
    $user = User::factory()->create();

    $action = new Create{{Model}}Action();
    ${{model}} = $action->execute($user, ['name' => 'Acme Corp']);

    expect(${{model}}->users)->toHaveCount(1)
        ->and(${{model}}->users->first()->id)->toBe($user->id);
});

it('has fillable attributes', function (): void {
    ${{model}} = new {{Model}}();

    expect(${{model}}->getFillable())->toContain('name', 'slug', 'owner_id');
});

it('uses soft deletes', function (): void {
    $user = User::factory()->create();

    $action = new Create{{Model}}Action();
    ${{model}} = $action->execute($user, ['name' => 'Acme Corp']);

    ${{model}}->delete();

    expect({{Model}}::query()->count())->toBe(0)
        ->and({{Model}}::query()->withTrashed()->count())->toBe(1);
});
