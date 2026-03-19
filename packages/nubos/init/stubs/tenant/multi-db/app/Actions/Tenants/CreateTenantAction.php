<?php

declare(strict_types=1);

namespace App\Actions\Tenants;

use App\Events\Tenants\TenantCreated;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantDatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CreateTenantAction
{
    private const MAX_SLUG_RETRIES = 3;

    public function __construct(
        private readonly TenantDatabaseManager $databaseManager,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function execute(User $user, array $data): Tenant
    {
        return $this->executeWithSlugRetry(function (string $slug) use ($user, $data): Tenant {
            $tenant = DB::transaction(function () use ($user, $data, $slug): Tenant {
                $tenant = Tenant::query()->create([
                    'name' => $data['name'],
                    'slug' => $slug,
                    'database' => 'tenant_' . Str::snake(Str::replace('-', '_', $slug)),
                ]);

                $tenant->members()->attach($user, ['role' => 'owner']);

                return $tenant;
            });

            try {
                $this->databaseManager->createDatabase($tenant);
            } catch (\Throwable $e) {
                $tenant->forceDelete();

                throw $e;
            }

            TenantCreated::dispatch($tenant);

            return $tenant;
        }, $data['name']);
    }

    /**
     * @param callable(string): Tenant $callback
     */
    private function executeWithSlugRetry(callable $callback, string $name): Tenant
    {
        $slug = Str::slug($name);

        for ($attempt = 0; $attempt <= self::MAX_SLUG_RETRIES; $attempt++) {
            $candidateSlug = $attempt === 0 ? $slug : "{$slug}-{$attempt}";

            $this->guardAgainstReservedSubdomain($candidateSlug);

            try {
                return $callback($candidateSlug);
            } catch (UniqueConstraintViolationException) {
                if ($attempt === self::MAX_SLUG_RETRIES) {
                    throw new RuntimeException("Failed to generate unique slug for: {$name}");
                }
            }
        }

        throw new RuntimeException("Failed to generate unique slug for: {$name}");
    }

    /**
     * @throws ValidationException
     */
    private function guardAgainstReservedSubdomain(string $slug): void
    {
        /** @var array<int, string> $reserved */
        $reserved = config('nubos.reserved_subdomains', []);

        if (in_array($slug, $reserved, true)) {
            throw ValidationException::withMessages([
                'name' => "The name resolves to a reserved subdomain: {$slug}",
            ]);
        }
    }
}
