<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Authorization\SyncUserRolesAction;
use App\Actions\Teams\SyncTeamMembersAction;
use App\DTOs\Users\InvitationResultData;
use App\Enums\Users\Salutation;
use App\Enums\Users\UserStatus;
use App\Models\Team;
use App\Models\User;
use App\Support\Authorization\RoleInputRules;
use App\Support\Users\EmailListParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class InviteUsersAction
{
    private string $emptyReason = 'i18n.backend.actions.users.invite_users_action.enter_at_least_one_email_address';

    public function __construct(
        private readonly EmailListParser $parser,
        private readonly IssueInvitationAction $issueInvitation,
        private readonly SyncUserRolesAction $syncUserRoles,
        private readonly SyncTeamMembersAction $syncTeamMembers,
        private readonly RoleInputRules $rules,
    ) {}

    /**
     * @param  array{emails: string, role_ids?: list<string>, team_ids?: list<string>}  $data
     *
     * @throws Throwable
     */
    public function execute(User $actingUser, array $data): InvitationResultData
    {
        Validator::make($data, array_merge($this->rules->roleIds('present'), [
            'emails' => ['required', 'string'],
            'team_ids' => ['present', 'array'],
            'team_ids.*' => ['string', 'ulid'],
        ]))->validate();

        $addresses = $this->validatedAddresses($data['emails']);
        $roleIds = $data['role_ids'] ?? [];
        $teamIds = $data['team_ids'] ?? [];
        $anchorTeam = $this->anchorTeam($actingUser, $teamIds);

        $invited = [];
        $renewed = [];
        $skipped = [];

        foreach ($addresses as $address) {
            $existing = User::withTrashed()->where('email', $address)->first();

            if ($existing instanceof User && $existing->tenant_id !== $actingUser->tenant_id) {
                $invited[] = $address;

                continue;
            }

            if ($existing instanceof User && !$this->isRenewable($existing)) {
                $skipped[] = $address;

                continue;
            }

            $user = DB::transaction(function () use ($existing, $actingUser, $address, $anchorTeam, $roleIds, $teamIds): User {
                $user = $existing instanceof User
                    ? $this->reopen($existing)
                    : $this->createInvitee($actingUser, $address, $anchorTeam);

                if ($roleIds !== []) {
                    $this->syncUserRoles->execute($user, $roleIds);
                }

                if ($teamIds !== []) {
                    $this->syncTeamMembers->syncTeamsOfUser($actingUser, $user, $teamIds, 'members.invite');
                }

                return $user;
            });

            $this->issueInvitation->execute($user, $actingUser);

            if ($existing instanceof User) {
                $renewed[] = $address;

                continue;
            }

            $invited[] = $address;
        }

        return new InvitationResultData($invited, $renewed, $skipped);
    }

    private function isRenewable(User $existing): bool
    {
        return $existing->status === UserStatus::Invited || $existing->trashed();
    }

    private function reopen(User $existing): User
    {
        if (!$existing->trashed()) {
            return $existing;
        }

        $existing->restore();
        $existing->roleAssignments()->delete();
        $existing->permissionOverrides()->delete();
        $existing->forgetResolvedRoles();

        $existing->forceFill(['status' => UserStatus::Invited])->save();

        return $existing;
    }

    /**
     * @return list<string>
     */
    private function validatedAddresses(string $emails): array
    {
        $addresses = $this->parser->parse($emails);

        if ($addresses === []) {
            throw ValidationException::withMessages(['emails' => __($this->emptyReason)]);
        }

        $cap = (int) config('users.invitation.max_per_request');

        if (count($addresses) > $cap) {
            throw ValidationException::withMessages([
                'emails' => __('i18n.backend.actions.users.invite_users_action.you_can_invite_at_most_addresses_at_once', ['value1' => $cap]),
            ]);
        }

        $invalid = $this->parser->invalid($addresses);

        if ($invalid !== []) {
            $listed = implode(', ', $invalid);

            throw ValidationException::withMessages([
                'emails' => __('i18n.backend.actions.users.invite_users_action.these_addresses_are_invalid', ['value1' => $listed]),
            ]);
        }

        return $addresses;
    }

    private function createInvitee(User $actingUser, string $address, ?Team $anchorTeam): User
    {
        return User::query()->create([
            'tenant_id' => $actingUser->tenant_id,
            'status' => UserStatus::Invited,
            'salutation' => Salutation::Unknown,
            'email' => $address,
            'invited_by_id' => $actingUser->getKey(),
            'current_team_id' => $anchorTeam?->getKey(),
        ]);
    }

    /**
     * @param  list<string>  $teamIds
     */
    private function anchorTeam(User $actingUser, array $teamIds): ?Team
    {
        if ($teamIds === []) {
            return null;
        }

        return Team::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $actingUser->tenant_id)
            ->whereNull('deleted_at')
            ->whereKey($teamIds)
            ->first();
    }
}
