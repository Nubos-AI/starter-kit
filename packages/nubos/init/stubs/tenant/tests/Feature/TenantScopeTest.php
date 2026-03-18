<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenantA = Tenant::factory()->create(['slug' => 'alpha']);
    $this->tenantB = Tenant::factory()->create(['slug' => 'bravo']);
});

it('scopes queries to the current tenant when bound', function (): void {
    $userA = User::factory()->create();
    $this->tenantA->members()->attach($userA, ['role' => 'member']);

    $userB = User::factory()->create();
    $this->tenantB->members()->attach($userB, ['role' => 'member']);

    app()->instance('currentTenant', $this->tenantA);

    $members = $this->tenantA->members;
    expect($members)->toHaveCount(1)
        ->and($members->first()->id)->toBe($userA->id);
});

it('does not apply scope when no tenant is bound', function (): void {
    $this->tenantA->members()->attach(User::factory()->create(), ['role' => 'member']);
    $this->tenantB->members()->attach(User::factory()->create(), ['role' => 'member']);

    expect(app()->bound('currentTenant'))->toBeFalse();

    expect(Tenant::query()->count())->toBe(2);
});

it('resolves tenant membership correctly', function (): void {
    $user = User::factory()->create();
    $this->tenantA->members()->attach($user, ['role' => 'owner']);

    expect($user->belongsToTenant($this->tenantA))->toBeTrue()
        ->and($user->belongsToTenant($this->tenantB))->toBeFalse()
        ->and($user->ownsTenant($this->tenantA))->toBeTrue()
        ->and($user->ownsTenant($this->tenantB))->toBeFalse();
});
