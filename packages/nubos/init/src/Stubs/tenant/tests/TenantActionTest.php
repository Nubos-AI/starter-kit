<?php

declare(strict_types=1);

use App\Actions\Tenants\AddTenantMemberAction;
use App\Actions\Tenants\CreateTenantAction;
use App\Actions\Tenants\RemoveTenantMemberAction;
use App\Events\Tenants\TenantCreated;
use App\Models\User;
use Illuminate\Support\Facades\Event;

describe('CreateTenantAction', function (): void {
    it('creates a tenant with owner and domain', function (): void {
        $user = User::factory()->create();

        $action = app(CreateTenantAction::class);
        $tenant = $action->execute($user, ['name' => 'Acme Corp', 'slug' => 'acme']);

        expect($tenant->name)->toBe('Acme Corp')
            ->and($tenant->slug)->toBe('acme')
            ->and($tenant->owner_id)->toBe($user->id)
            ->and($tenant->users)->toHaveCount(1)
            ->and($tenant->domains)->toHaveCount(1)
            ->and($tenant->domains->first()->domain)->toBe('acme');
    });

    it('dispatches TenantCreated event', function (): void {
        Event::fake();

        $user = User::factory()->create();

        $action = app(CreateTenantAction::class);
        $action->execute($user, ['name' => 'Acme', 'slug' => 'acme']);

        Event::assertDispatched(TenantCreated::class);
    });

    it('rejects reserved subdomains', function (): void {
        $user = User::factory()->create();

        $action = app(CreateTenantAction::class);

        expect(fn () => $action->execute($user, ['name' => 'WWW', 'slug' => 'www']))
            ->toThrow(InvalidArgumentException::class);
    });

    it('rejects duplicate subdomains', function (): void {
        $user = User::factory()->create();

        $action = app(CreateTenantAction::class);
        $action->execute($user, ['name' => 'Acme', 'slug' => 'acme']);

        expect(fn () => $action->execute($user, ['name' => 'Acme 2', 'slug' => 'acme']))
            ->toThrow(InvalidArgumentException::class);
    });

    it('rejects too short subdomains', function (): void {
        $user = User::factory()->create();

        $action = app(CreateTenantAction::class);

        expect(fn () => $action->execute($user, ['name' => 'A', 'slug' => 'a']))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('AddTenantMemberAction', function (): void {
    it('adds a member to the tenant', function (): void {
        $owner = User::factory()->create();
        $member = User::factory()->create();

        $createAction = app(CreateTenantAction::class);
        $tenant = $createAction->execute($owner, ['name' => 'Acme', 'slug' => 'acme']);

        $addAction = app(AddTenantMemberAction::class);
        $addAction->execute($tenant, $member, 'member');

        expect($tenant->fresh()->users)->toHaveCount(2);
    });
});

describe('RemoveTenantMemberAction', function (): void {
    it('removes a member from the tenant', function (): void {
        $owner = User::factory()->create();
        $member = User::factory()->create();

        $createAction = app(CreateTenantAction::class);
        $tenant = $createAction->execute($owner, ['name' => 'Acme', 'slug' => 'acme']);

        $addAction = app(AddTenantMemberAction::class);
        $addAction->execute($tenant, $member);

        $removeAction = app(RemoveTenantMemberAction::class);
        $removeAction->execute($tenant, $member);

        expect($tenant->fresh()->users)->toHaveCount(1);
    });
});
