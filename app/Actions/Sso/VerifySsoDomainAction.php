<?php

declare(strict_types=1);

namespace App\Actions\Sso;

use App\Exceptions\Tenancy\DnsLookupFailedException;
use App\Models\SsoConnection;
use App\Support\Audit\AdminArtifactAuditor;
use App\Support\Tenancy\DnsLookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class VerifySsoDomainAction
{
    public function __construct(
        private readonly AdminArtifactAuditor $auditor,
        private readonly DnsLookup $dns,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     * @throws Throwable
     */
    public function execute(string $tenantId, array $data): SsoConnection
    {
        $validated = Validator::make($data, [
            'domain' => ['required', 'string', 'max:253'],
        ])->validate();

        $domain = Str::of((string) $validated['domain'])->trim()->lower()->ltrim('@')->toString();
        $connection = SsoConnection::query()->where('tenant_id', $tenantId)->first();

        if (!$connection instanceof SsoConnection || !in_array($domain, $connection->allowed_domains ?? [], true)) {
            throw ValidationException::withMessages([
                'allowed_domains' => __('i18n.backend.actions.sso.verify_sso_domain_action.domain_not_listed', ['domain' => $domain]),
            ]);
        }

        $this->assertDomainIsUnproven($tenantId, $domain);

        $record = $connection->domainProofRecord($domain);

        if (!in_array($record['value'], $this->txtRecordsOf($tenantId, $record['name']), true)) {
            Log::info('A single sign-on domain was refused because its TXT record is missing.', ['tenant_id' => $tenantId, 'connection_id' => $connection->getKey(), 'host' => $record['name']]);

            throw ValidationException::withMessages([
                'allowed_domains' => __('i18n.backend.actions.sso.verify_sso_domain_action.record_missing', ['domain' => $domain, 'name' => $record['name'], 'value' => $record['value']]),
            ]);
        }

        $connection->fill([
            'verified_domains' => array_values(array_unique([...($connection->verified_domains ?? []), $domain])),
        ]);

        return DB::transaction(function () use ($connection, $tenantId, $domain): SsoConnection {
            $connection->save();

            $this->auditor->recordEvent($connection, 'sso.domain_verified', [
                'domain' => $domain,
                'verified_domains' => $connection->verified_domains,
            ], $tenantId);

            Log::info('A single sign-on domain was verified.', ['tenant_id' => $tenantId, 'connection_id' => $connection->getKey(), 'domain' => $domain]);

            return $connection;
        });
    }

    /**
     * @throws ValidationException
     */
    private function assertDomainIsUnproven(string $tenantId, string $domain): void
    {
        $isProvenElsewhere = SsoConnection::withoutTenantScope()
            ->where('sso_connections.tenant_id', '!=', $tenantId)
            ->whereJsonContains('sso_connections.verified_domains', $domain)
            ->exists();

        if (!$isProvenElsewhere) {
            return;
        }

        Log::info('A single sign-on domain was refused because another tenant proved it.', ['tenant_id' => $tenantId, 'domain' => $domain]);

        throw ValidationException::withMessages([
            'allowed_domains' => __('i18n.backend.actions.sso.verify_sso_domain_action.domain_taken', ['domain' => $domain]),
        ]);
    }

    /**
     * @return list<string>
     *
     * @throws ValidationException
     */
    private function txtRecordsOf(string $tenantId, string $host): array
    {
        try {
            return $this->dns->txtOf($host);
        } catch (DnsLookupFailedException $exception) {
            Log::warning('A single sign-on domain could not be verified.', ['tenant_id' => $tenantId, 'host' => $exception->host]);

            throw ValidationException::withMessages([
                'allowed_domains' => __('i18n.backend.actions.sso.verify_sso_domain_action.dns_unavailable'),
            ]);
        }
    }
}
