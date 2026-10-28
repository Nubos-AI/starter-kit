<?php

declare(strict_types=1);

namespace App\Http\Controllers\Skills;

use App\Actions\Skills\BulkDeleteSkillsAction;
use App\Actions\Skills\CreateSkillAction;
use App\Actions\Skills\DeleteSkillAction;
use App\Actions\Skills\SyncSkillUsersAction;
use App\Actions\Skills\UpdateSkillAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\Skill;
use App\Models\User;
use App\Support\Users\UserOptionPresenter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SkillsController extends Controller
{
    private string $deniedReason = 'i18n.backend.http.controllers.skills.skills_controller.no_permission';

    public function __construct(
        private readonly CreateSkillAction $createSkill,
        private readonly UpdateSkillAction $updateSkill,
        private readonly DeleteSkillAction $deleteSkill,
        private readonly BulkDeleteSkillsAction $bulkDeleteSkills,
        private readonly SyncSkillUsersAction $syncSkillUsers,
        private readonly UserOptionPresenter $userOptions,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->actingUser($request);

        $skills = $this->tenantSkills($user)
            ->map(fn (Skill $skill): array => $this->indexPayload($skill, $user))
            ->all();

        return Inertia::render('skills/Index', [
            'skills' => $skills,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('skills/Form', [
            'mode' => 'create',
            'skill' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->createSkill->execute($this->actingUser($request), $request->all());

        return to_route('engine.skills.index');
    }

    public function edit(Request $request, Skill $skill): Response
    {
        $this->assertOwnTenant($this->actingUser($request), $skill);

        return Inertia::render('skills/Form', [
            'mode' => 'edit',
            'skill' => $this->payload($skill),
            'userOptions' => $this->userOptionsFor($this->actingUser($request)),
        ]);
    }

    public function update(Request $request, Skill $skill): RedirectResponse
    {
        $this->assertOwnTenant($this->actingUser($request), $skill);

        $this->updateSkill->execute($skill, $request->all());

        $this->syncSkillUsers->execute($skill, $this->submittedUserIds($request));

        return to_route('engine.skills.index');
    }

    public function destroy(Request $request, Skill $skill): RedirectResponse
    {
        $this->assertOwnTenant($this->actingUser($request), $skill);

        $this->deleteSkill->execute($skill);

        return to_route('engine.skills.index');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->bulkDeleteSkills->execute($this->actingUser($request), $request->all());

        return to_route('engine.skills.index');
    }

    private function assertOwnTenant(User $user, Skill $skill): void
    {
        abort_if($skill->tenant_id !== (string) $user->tenant_id, 404);
    }

    /**
     * @return Collection<int, Skill>
     */
    private function tenantSkills(User $user): Collection
    {
        $tenantId = $user->tenant_id;

        if ($tenantId === null) {
            return new Collection;
        }

        return Skill::query()
            ->where('tenant_id', $tenantId)
            ->withCount('users')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function indexPayload(Skill $skill, User $user): array
    {
        $canDelete = $user->can('delete', $skill);

        return [
            'id' => (string) $skill->getKey(),
            'name' => $skill->name,
            'users_count' => (int) $skill->getAttribute('users_count'),
            'can_update' => $user->can('update', $skill),
            'can_delete' => $canDelete,
            'delete_reason' => $canDelete ? null : __($this->deniedReason),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Skill $skill): array
    {
        return [
            'id' => (string) $skill->getKey(),
            'name' => $skill->name,
            'userIds' => $skill->users()
                ->orderBy('users.last_name')
                ->orderBy('users.first_name')
                ->pluck('users.id')
                ->map(fn (mixed $id): string => (string) $id)
                ->all(),
        ];
    }

    /**
     * @return list<array{value: string, label: string, description: string, avatar: array{name: string}}>
     */
    private function userOptionsFor(User $user): array
    {
        $tenantId = $user->tenant_id;

        if ($tenantId === null) {
            return [];
        }

        return $this->userOptions->presentMany(
            User::query()
                ->where('tenant_id', $tenantId)
                ->where('is_service', false)
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get(),
        );
    }

    /**
     * @return list<string>
     */
    private function submittedUserIds(Request $request): array
    {
        $ids = $request->input('user_ids', []);

        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $id): string => is_scalar($id) ? (string) $id : '',
            $ids,
        ));
    }
}
