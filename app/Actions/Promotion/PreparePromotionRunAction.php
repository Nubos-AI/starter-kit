<?php

declare(strict_types=1);

namespace App\Actions\Promotion;

use App\Contracts\Promotion\TenantPromotionSourceInterface;
use App\Enums\Promotion\PromotionDirection;
use App\Enums\Promotion\PromotionRunStatus;
use App\Exceptions\Promotion\PromotionSourceUnavailableException;
use App\Models\PromotionRun;
use App\Models\User;
use App\Support\Promotion\PromotionSourceResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class PreparePromotionRunAction
{
    public function __construct(
        private readonly PromotionSourceResolver $sourceResolver,
        private readonly TenantPromotionSourceInterface $tenantSource,
    ) {}

    /**
     * @throws ValidationException
     * @throws Throwable
     */
    public function execute(User $actingUser): PromotionRun
    {
        try {
            $this->tenantSource->assertContextAllowed();
            $source = $this->tenantSource->sourceFor($actingUser);
        } catch (PromotionSourceUnavailableException $exception) {
            throw ValidationException::withMessages([$this->tenantSource->validationKey() => $exception->getMessage()]);
        }

        $sourceTenantId = (string) $source->getKey();

        $run = PromotionRun::query()->make([
            'tenant_id' => (string) $actingUser->tenant_id,
            'source_tenant_id' => $sourceTenantId,
            'triggered_by_id' => (string) $actingUser->getKey(),
            'counterpart_key' => $this->tenantSource->counterpartKey($source),
            'direction' => PromotionDirection::TenantToProduction,
            'status' => PromotionRunStatus::Draft,
            'selection' => [],
            'conflict_decisions' => [],
        ]);

        return DB::transaction(function () use ($run): PromotionRun {
            try {
                $this->sourceResolver->assertResolvable($run);
            } catch (PromotionSourceUnavailableException $exception) {
                throw ValidationException::withMessages([$this->tenantSource->validationKey() => $exception->getMessage()]);
            }

            $run->save();

            return $run;
        });
    }
}
