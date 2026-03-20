<?php

declare(strict_types=1);

namespace App\Actions\Tenants;

// @nubos:multi-db-start
// @nubos:multi-db-end
use App\Events\Tenants\TenantCreated;
use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class CreateTenantAction
{
    private const RESERVED_SUBDOMAINS = [
        'www', 'api', 'admin', 'app', 'mail', 'ftp', 'staging', 'preview',
    ];

    // @nubos:multi-db-start
    public function __construct(
        private readonly ConfigureTenantDatabaseAction $configureTenantDatabase,
    ) {}
    // @nubos:multi-db-end

    /**
     * @throws Throwable
     */
    public function execute(User $owner, array $data): Tenant
    {
        return DB::transaction(function () use ($owner, $data): Tenant {
            $subdomain = $data['slug'] ?? Str::slug($data['name']);

            $this->validateSubdomain($subdomain);

            $tenant = Tenant::query()->create([
                'name' => $data['name'],
                'slug' => $subdomain,
                'owner_id' => $owner->id,
            ]);

            Domain::query()->create([
                'tenant_id' => $tenant->id,
                'domain' => $subdomain,
                'is_primary' => true,
            ]);

            $tenant->users()->attach($owner->id, ['role' => 'owner']);

            event(new TenantCreated($tenant));

            // @nubos:multi-db-start
            if (config('nubos.database_strategy') === 'multi') {
                $this->configureTenantDatabase->execute($tenant);
            }
            // @nubos:multi-db-end

            return $tenant;
        });
    }

    private function validateSubdomain(string $subdomain): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/', $subdomain)) {
            throw new InvalidArgumentException("Subdomain '{$subdomain}' has invalid format.");
        }

        if (in_array($subdomain, self::RESERVED_SUBDOMAINS, true)) {
            throw new InvalidArgumentException("Subdomain '{$subdomain}' is reserved.");
        }

        if (Domain::query()->where('domain', $subdomain)->exists()) {
            throw new InvalidArgumentException("Subdomain '{$subdomain}' is already taken.");
        }
    }
}
