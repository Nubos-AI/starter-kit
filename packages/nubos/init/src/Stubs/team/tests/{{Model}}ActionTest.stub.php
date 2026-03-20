<?php

declare(strict_types=1);

use App\Actions\{{Models}}\Add{{Model}}MemberAction;
use App\Actions\{{Models}}\Create{{Model}}Action;
use App\Actions\{{Models}}\Remove{{Model}}MemberAction;
use App\Events\{{Models}}\{{Model}}Created;
use App\Events\{{Models}}\{{Model}}MemberAdded;
use App\Events\{{Models}}\{{Model}}MemberRemoved;
use App\Models\User;
use Illuminate\Support\Facades\Event;

describe('Create{{Model}}Action', function (): void {
    it('creates a {{model}} with owner', function (): void {
        $user = User::factory()->create();

        $action = new Create{{Model}}Action();
        ${{model}} = $action->execute($user, ['name' => 'Acme Corp']);

        expect(${{model}}->name)->toBe('Acme Corp')
            ->and(${{model}}->slug)->toBe('acme-corp')
            ->and(${{model}}->owner_id)->toBe($user->id)
            ->and(${{model}}->users)->toHaveCount(1)
            ->and($user->fresh()->current_{{model}}_id)->toBe(${{model}}->id);
    });

    it('dispatches {{Model}}Created event', function (): void {
        Event::fake();

        $user = User::factory()->create();

        $action = new Create{{Model}}Action();
        $action->execute($user, ['name' => 'Acme Corp']);

        Event::assertDispatched({{Model}}Created::class);
    });
});

describe('Add{{Model}}MemberAction', function (): void {
    it('adds a member to the {{model}}', function (): void {
        $owner = User::factory()->create();
        $member = User::factory()->create();

        $action = new Create{{Model}}Action();
        ${{model}} = $action->execute($owner, ['name' => 'Acme Corp']);

        $addAction = new Add{{Model}}MemberAction();
        $addAction->execute(${{model}}, $member, 'member');

        expect(${{model}}->users)->toHaveCount(2);
    });

    it('dispatches {{Model}}MemberAdded event', function (): void {
        Event::fake();

        $owner = User::factory()->create();
        $member = User::factory()->create();

        $action = new Create{{Model}}Action();
        ${{model}} = $action->execute($owner, ['name' => 'Acme Corp']);

        $addAction = new Add{{Model}}MemberAction();
        $addAction->execute(${{model}}, $member);

        Event::assertDispatched({{Model}}MemberAdded::class);
    });
});

describe('Remove{{Model}}MemberAction', function (): void {
    it('removes a member from the {{model}}', function (): void {
        $owner = User::factory()->create();
        $member = User::factory()->create();

        $action = new Create{{Model}}Action();
        ${{model}} = $action->execute($owner, ['name' => 'Acme Corp']);

        $addAction = new Add{{Model}}MemberAction();
        $addAction->execute(${{model}}, $member);

        $removeAction = new Remove{{Model}}MemberAction();
        $removeAction->execute(${{model}}, $member);

        expect(${{model}}->fresh()->users)->toHaveCount(1);
    });

    it('dispatches {{Model}}MemberRemoved event', function (): void {
        Event::fake();

        $owner = User::factory()->create();
        $member = User::factory()->create();

        $action = new Create{{Model}}Action();
        ${{model}} = $action->execute($owner, ['name' => 'Acme Corp']);

        $addAction = new Add{{Model}}MemberAction();
        $addAction->execute(${{model}}, $member);

        $removeAction = new Remove{{Model}}MemberAction();
        $removeAction->execute(${{model}}, $member);

        Event::assertDispatched({{Model}}MemberRemoved::class);
    });
});
