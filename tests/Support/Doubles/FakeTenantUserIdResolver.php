<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Support\Tenancy\TenantUserIdResolver;

class FakeTenantUserIdResolver extends TenantUserIdResolver
{
    /**
     * @param  list<string>  $known
     */
    private function __construct(private readonly array $known) {}

    /**
     * @param  list<string>  $userIds
     */
    public static function knowing(array $userIds): self
    {
        $resolver = new self($userIds);

        app()->instance(TenantUserIdResolver::class, $resolver);

        return $resolver;
    }

    /**
     * @param  list<string>  $userIds
     * @return list<string>
     */
    public function resolve(string $tenantId, array $userIds): array
    {
        return array_values(array_intersect($userIds, $this->known));
    }
}
