<?php

declare(strict_types=1);

use App\Actions\Teams\AddTeamMemberAction;
use App\Actions\Teams\CreateTeamAction;
use App\Actions\Teams\RemoveTeamMemberAction;
use App\Actions\Workspaces\CreateWorkspaceAction;
use App\Events\Teams\TeamCreated;
use App\Models\User;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->user = User::factory()->create();

    $workspaceAction = new CreateWorkspaceAction();
    $this->workspace = $workspaceAction->execute($this->user, ['name' => 'Acme Corp']);
});

describe('CreateTeamAction', function (): void {
    it('creates a team within workspace', function (): void {
        $action = new CreateTeamAction();
        $team = $action->execute($this->user, $this->workspace, ['name' => 'Engineering']);

        expect($team->name)->toBe('Engineering')
            ->and($team->workspace_id)->toBe($this->workspace->id)
            ->and($team->owner_id)->toBe($this->user->id)
            ->and($team->users)->toHaveCount(1);
    });

    it('dispatches TeamCreated event', function (): void {
        Event::fake();

        $action = new CreateTeamAction();
        $action->execute($this->user, $this->workspace, ['name' => 'Engineering']);

        Event::assertDispatched(TeamCreated::class);
    });
});

describe('AddTeamMemberAction', function (): void {
    it('adds a member to the team', function (): void {
        $member = User::factory()->create();

        $createAction = new CreateTeamAction();
        $team = $createAction->execute($this->user, $this->workspace, ['name' => 'Engineering']);

        $addAction = new AddTeamMemberAction();
        $addAction->execute($team, $member, 'member');

        expect($team->fresh()->users)->toHaveCount(2);
    });
});

describe('RemoveTeamMemberAction', function (): void {
    it('removes a member from the team', function (): void {
        $member = User::factory()->create();

        $createAction = new CreateTeamAction();
        $team = $createAction->execute($this->user, $this->workspace, ['name' => 'Engineering']);

        $addAction = new AddTeamMemberAction();
        $addAction->execute($team, $member);

        $removeAction = new RemoveTeamMemberAction();
        $removeAction->execute($team, $member);

        expect($team->fresh()->users)->toHaveCount(1);
    });
});
