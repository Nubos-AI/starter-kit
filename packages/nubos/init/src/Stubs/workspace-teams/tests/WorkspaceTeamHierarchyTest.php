<?php

declare(strict_types=1);

use App\Actions\Teams\CreateTeamAction;
use App\Actions\Workspaces\CreateWorkspaceAction;
use App\Models\User;

it('team belongs to workspace', function (): void {
    $user = User::factory()->create();

    $workspaceAction = new CreateWorkspaceAction();
    $workspace = $workspaceAction->execute($user, ['name' => 'Acme Corp']);

    $teamAction = new CreateTeamAction();
    $team = $teamAction->execute($user, $workspace, ['name' => 'Engineering']);

    expect($team->workspace_id)->toBe($workspace->id)
        ->and($team->workspace->id)->toBe($workspace->id);
});

it('denies cross-workspace team access', function (): void {
    $user = User::factory()->create();

    $workspaceAction = new CreateWorkspaceAction();
    $workspace1 = $workspaceAction->execute($user, ['name' => 'Workspace 1']);
    $workspace2 = $workspaceAction->execute($user, ['name' => 'Workspace 2']);

    $teamAction = new CreateTeamAction();
    $team = $teamAction->execute($user, $workspace1, ['name' => 'Engineering']);

    $this->actingAs($user)
        ->get("/workspaces/{$workspace2->slug}/teams/{$team->slug}/dashboard")
        ->assertForbidden();
});
