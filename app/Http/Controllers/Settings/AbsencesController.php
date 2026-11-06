<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Governance\BulkDeleteAbsenceDelegationsAction;
use App\Actions\Governance\CreateAbsenceDelegationAction;
use App\Actions\Governance\DeleteAbsenceDelegationAction;
use App\Actions\Governance\UpdateAbsenceDelegationAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\AbsenceDelegation;
use App\Models\User;
use App\Support\Users\UserOptionPresenter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class AbsencesController extends Controller
{
    private string $deniedReason = 'i18n.backend.http.controllers.settings.absences_controller.no_permission';

    public function __construct(
        private readonly CreateAbsenceDelegationAction $createAbsenceDelegation,
        private readonly UpdateAbsenceDelegationAction $updateAbsenceDelegation,
        private readonly DeleteAbsenceDelegationAction $deleteAbsenceDelegation,
        private readonly BulkDeleteAbsenceDelegationsAction $bulkDeleteAbsenceDelegations,
        private readonly UserOptionPresenter $userOptions,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actingUser($request);
        $subject = $this->subjectUser($actor, $request->query('user'));
        $canManage = $actor->hasPermission('absences.manage');
        $tenantUsers = $this->tenantUsers($actor);

        return Inertia::render('settings/Absences', [
            'subject' => [
                'id' => (string) $subject->getKey(),
                'name' => $this->userOptions->label($subject),
            ],
            'isOwnSubject' => (string) $subject->getKey() === (string) $actor->getKey(),
            'canManage' => $canManage,
            'delegations' => $this->delegationRows($actor, $subject),
            'delegateOptions' => $this->delegateOptions(
                $actor,
                $tenantUsers
                    ->reject(fn (User $user): bool => (string) $user->getKey() === (string) $subject->getKey())
                    ->values(),
            ),
            'manageableUserOptions' => $canManage ? $this->userOptions->presentMany($tenantUsers) : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->actingUser($request);

        $delegation = $this->createAbsenceDelegation->execute($actor, $request->all());

        return $this->redirectToSubject($actor, $delegation->user_id);
    }

    public function update(Request $request, AbsenceDelegation $absence): RedirectResponse
    {
        $actor = $this->actingUser($request);

        $this->updateAbsenceDelegation->execute($actor, $absence, $request->all());

        return $this->redirectToSubject($actor, $absence->user_id);
    }

    public function destroy(Request $request, AbsenceDelegation $absence): RedirectResponse
    {
        $actor = $this->actingUser($request);
        $subjectId = $absence->user_id;

        $this->deleteAbsenceDelegation->execute($actor, $absence);

        return $this->redirectToSubject($actor, $subjectId);
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $actor = $this->actingUser($request);
        $subject = $this->subjectUser($actor, $request->input('userId'));

        $this->bulkDeleteAbsenceDelegations->execute($actor, $request->all(), $subject);

        return $this->redirectToSubject($actor, (string) $subject->getKey());
    }

    /**
     * @param  Collection<int, User>  $candidates
     * @return list<array<string, mixed>>
     */
    private function delegateOptions(User $actor, Collection $candidates): array
    {
        if ($actor->hasPermission('members.view')) {
            return $this->userOptions->presentMany($candidates);
        }

        return array_map(
            static fn (array $option): array => Arr::except($option, 'description'),
            $this->userOptions->presentMany(
                $candidates->filter(fn (User $user): bool => trim($user->name) !== '')->values(),
            ),
        );
    }

    private function subjectUser(User $actor, mixed $userId): User
    {
        $tenantId = $actor->tenant_id;

        if (!is_string($userId) || $userId === '' || $tenantId === null) {
            return $actor;
        }

        if ($userId === (string) $actor->getKey() || !$actor->hasPermission('absences.manage')) {
            return $actor;
        }

        return User::query()
            ->where('tenant_id', $tenantId)
            ->where('is_service', false)
            ->whereKey($userId)
            ->firstOrFail();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function delegationRows(User $actor, User $subject): array
    {
        $tenantId = $subject->tenant_id;

        if ($tenantId === null) {
            return [];
        }

        return array_values(
            AbsenceDelegation::query()
                ->where('tenant_id', $tenantId)
                ->where('user_id', (string) $subject->getKey())
                ->with('delegate')
                ->orderBy('starts_at')
                ->get()
                ->map(fn (AbsenceDelegation $delegation): array => $this->delegationPayload($actor, $delegation))
                ->all(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function delegationPayload(User $actor, AbsenceDelegation $delegation): array
    {
        $canDelete = $actor->can('delete', $delegation);
        $delegate = $delegation->delegate;

        return [
            'id' => (string) $delegation->getKey(),
            'delegateId' => $delegation->delegate_id,
            'delegateName' => $delegate instanceof User ? $this->userOptions->label($delegate) : '',
            'startsAt' => $delegation->starts_at?->toDateString() ?? '',
            'endsAt' => $delegation->ends_at?->toDateString() ?? '',
            'can_update' => $actor->can('update', $delegation),
            'can_delete' => $canDelete,
            'delete_reason' => $canDelete ? null : __($this->deniedReason),
        ];
    }

    /**
     * @return Collection<int, User>
     */
    private function tenantUsers(User $actor): Collection
    {
        $tenantId = $actor->tenant_id;

        if ($tenantId === null) {
            return new Collection;
        }

        return User::query()
            ->where('tenant_id', $tenantId)
            ->where('is_service', false)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    private function redirectToSubject(User $actor, string $subjectId): RedirectResponse
    {
        if ($subjectId === (string) $actor->getKey()) {
            return to_route('settings.absences.index');
        }

        return to_route('settings.absences.index', ['user' => $subjectId]);
    }
}
