<?php

declare(strict_types=1);

namespace App\Support\Promotion;

use App\Contracts\Promotion\TenantPromotionSourceInterface;
use App\DTOs\ConfigBundle\ConfigBundle;
use App\Enums\Promotion\PromotionDirection;
use App\Exceptions\ConfigBundle\MalformedBundleException;
use App\Exceptions\Promotion\PromotionSourceUnavailableException;
use App\Models\PromotionRun;
use App\Support\ConfigBundle\ConfigBundleReader;
use App\Support\ConfigBundle\ConfigBundleSerializer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PromotionSourceResolver
{
    public function __construct(
        private readonly ConfigBundleSerializer $serializer,
        private readonly ConfigBundleReader $reader,
        private readonly TenantPromotionSourceInterface $tenantSource,
    ) {}

    public function directoryFor(PromotionRun $run): string
    {
        return "config-imports/{$run->tenant_id}/{$run->getKey()}";
    }

    /**
     * @throws PromotionSourceUnavailableException
     */
    public function assertResolvable(PromotionRun $run): void
    {
        if ($run->direction === PromotionDirection::BundleImport) {
            $this->assertBundleDirectoryStored($run);

            return;
        }

        $this->tenantSource->assertReady($run);
    }

    /**
     * @throws PromotionSourceUnavailableException
     * @throws MalformedBundleException
     * @throws Throwable
     */
    public function resolve(PromotionRun $run): ConfigBundle
    {
        if ($run->direction === PromotionDirection::BundleImport) {
            $this->assertBundleDirectoryStored($run);

            return $this->reader->readFrom(Storage::disk('local')->path($this->directoryFor($run)));
        }

        return DB::transaction(function () use ($run): ConfigBundle {
            $this->tenantSource->assertReady($run);

            $bundle = $this->serializer->serialize((string) $run->source_tenant_id);

            $this->tenantSource->assertReady($run);

            return $bundle;
        });
    }

    /**
     * @throws PromotionSourceUnavailableException
     */
    private function assertBundleDirectoryStored(PromotionRun $run): void
    {
        if (!Storage::disk('local')->directoryExists($this->directoryFor($run))) {
            throw PromotionSourceUnavailableException::bundleDirectoryMissing();
        }
    }
}
