<?php

declare(strict_types=1);

namespace App\Actions\Governance;

use App\Models\AbsenceDelegation;
use App\Models\User;
use App\Support\Governance\AbsenceOverlapGuard;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateAbsenceDelegationAction
{
    public function __construct(private readonly AbsenceOverlapGuard $guard) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws Throwable
     */
    public function execute(User $actor, array $input): AbsenceDelegation
    {
        $tenantId = TenantContext::currentId($actor->tenant_id);

        if ($tenantId === null) {
            throw new AuthorizationException(__('i18n.backend.actions.governance.create_absence_delegation_action.absence_delegation_requires_a_bound_tenant'));
        }

        $validated = Validator::make($input, [
            'userId' => [
                'required',
                'string',
                'ulid',
                Rule::exists('users', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
            'delegateId' => [
                'required',
                'string',
                'ulid',
                'different:userId',
                Rule::exists('users', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
            'startsAt' => ['required', 'date'],
            'endsAt' => ['required', 'date', 'after:startsAt'],
        ])->validate();

        $userId = (string) $validated['userId'];

        if ($userId !== (string) $actor->getKey() && !$actor->hasPermission('absences.manage')) {
            throw new AuthorizationException(__('i18n.backend.actions.governance.create_absence_delegation_action.entering_an_absence_for_another_person_requires_the_absences'));
        }

        $delegateId = (string) $validated['delegateId'];
        $startsAt = CarbonImmutable::parse((string) $validated['startsAt'])->setTimezone('UTC');
        $endsAt = CarbonImmutable::parse((string) $validated['endsAt'])->setTimezone('UTC');
        $createdById = (string) $actor->getKey();

        return DB::transaction(function () use ($tenantId, $userId, $delegateId, $createdById, $startsAt, $endsAt): AbsenceDelegation {
            $this->guard->assertNoOverlap($tenantId, $userId, $startsAt, $endsAt);

            $delegation = new AbsenceDelegation;

            $delegation->fill([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'delegate_id' => $delegateId,
                'created_by_id' => $createdById,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);

            $delegation->save();

            return $delegation;
        });
    }
}
