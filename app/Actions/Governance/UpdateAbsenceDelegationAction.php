<?php

declare(strict_types=1);

namespace App\Actions\Governance;

use App\Models\AbsenceDelegation;
use App\Models\User;
use App\Support\Governance\AbsenceOverlapGuard;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class UpdateAbsenceDelegationAction
{
    public function __construct(private readonly AbsenceOverlapGuard $guard) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws Throwable
     */
    public function execute(User $actor, AbsenceDelegation $delegation, array $input): AbsenceDelegation
    {
        Gate::forUser($actor)->authorize('update', $delegation);

        $tenantId = $delegation->tenant_id;
        $userId = $delegation->user_id;

        $validated = Validator::make($input, [
            'delegateId' => [
                'required',
                'string',
                'ulid',
                Rule::notIn([$userId]),
                Rule::exists('users', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
            'startsAt' => ['required', 'date'],
            'endsAt' => ['required', 'date', 'after:startsAt'],
        ])->validate();

        $delegateId = (string) $validated['delegateId'];
        $startsAt = CarbonImmutable::parse((string) $validated['startsAt'])->setTimezone('UTC');
        $endsAt = CarbonImmutable::parse((string) $validated['endsAt'])->setTimezone('UTC');
        $updatedById = (string) $actor->getKey();
        $exceptId = (string) $delegation->getKey();

        return DB::transaction(function () use ($delegation, $tenantId, $userId, $delegateId, $updatedById, $startsAt, $endsAt, $exceptId): AbsenceDelegation {
            $this->guard->assertNoOverlap($tenantId, $userId, $startsAt, $endsAt, $exceptId);

            $delegation->fill([
                'delegate_id' => $delegateId,
                'updated_by_id' => $updatedById,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);

            $delegation->save();

            return $delegation;
        });
    }
}
