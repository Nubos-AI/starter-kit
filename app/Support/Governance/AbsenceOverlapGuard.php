<?php

declare(strict_types=1);

namespace App\Support\Governance;

use App\Exceptions\Governance\OverlapCheckOutsideTransactionException;
use App\Models\AbsenceDelegation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AbsenceOverlapGuard
{
    /**
     * @throws OverlapCheckOutsideTransactionException
     * @throws ValidationException
     */
    public function assertNoOverlap(
        string $tenantId,
        string $userId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?string $exceptId = null,
    ): void {
        if (DB::transactionLevel() === 0) {
            throw OverlapCheckOutsideTransactionException::forUser($userId);
        }

        DB::select('select pg_advisory_xact_lock(hashtext(?::text), hashtext(?::text))', [$tenantId, $userId]);

        $overlaps = AbsenceDelegation::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->overlapping($startsAt, $endsAt)
            ->when($exceptId !== null, fn (Builder $query): Builder => $query->whereKeyNot($exceptId))
            ->lockForUpdate()
            ->exists();

        if (!$overlaps) {
            return;
        }

        throw ValidationException::withMessages([
            'startsAt' => __('i18n.backend.support.governance.absence_overlap_guard.the_absence_period_overlaps_an_existing_period'),
        ]);
    }
}
