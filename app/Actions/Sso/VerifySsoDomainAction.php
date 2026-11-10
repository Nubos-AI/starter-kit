<?php

declare(strict_types=1);

namespace App\Actions\Sso;

use App\Exceptions\Tenancy\DnsLookupFailedException;
use App\Models\SsoConnection;
use App\Models\SsoVerifiedDomain;
use App\Support\Audit\AdminArtifactAuditor;
use App\Support\Tenancy\DnsLookup;
use Illuminate\Database\UniqueConstraintViolationException;
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

        if ($connection->domain_proof_nonce === null || $connection->domain_proof_nonce === '') {
            Log::info('A single sign-on domain was refused because the connection carries no domain challenge.', ['tenant_id' => $tenantId, 'connection_id' => $connection->getKey()]);

            throw ValidationException::withMessages([
                'allowed_domains' => __('i18n.backend.actions.sso.verify_sso_domain_action.challenge_missing'),
            ]);
        }

        $record = $connection->domainProofRecord($domain);

        if (!in_array($record['value'], $this->txtRecordsOf($tenantId, $record['name']), true)) {
            Log::info('A single sign-on domain was refused because its TXT record is missing.', ['tenant_id' => $tenantId, 'connection_id' => $connection->getKey(), 'host' => $record['name']]);

            throw ValidationException::withMessages([
                'allowed_domains' => __('i18n.backend.actions.sso.verify_sso_domain_action.record_missing', ['domain' => $domain, 'name' => $record['name'], 'value' => $record['value']]),
            ]);
        }

        try {
            return DB::transaction(function () use ($connection, $tenantId, $domain): SsoConnection {
                $this->claimDomain($connection, $tenantId, $domain);

                $connection->unsetRelation('verifiedDomains');

                $this->auditor->recordEvent($connection, 'sso.domain_verified', [
                    'domain' => $domain,
                ], $tenantId);

                Log::info('A single sign-on domain was verified.', ['tenant_id' => $tenantId, 'connection_id' => $connection->getKey(), 'domain' => $domain]);

                return $connection;
            });
        } catch (UniqueConstraintViolationException) {
            Log::info('A single sign-on domain was refused because another tenant proved it first.', ['tenant_id' => $tenantId, 'domain' => $domain]);

            throw $this->domainTaken($domain);
        }
    }

    private function claimDomain(SsoConnection $connection, string $tenantId, string $domain): void
    {
        $isClaimed = $connection->verifiedDomains()
            ->where('sso_verified_domains.domain', $domain)
            ->exists();

        if ($isClaimed) {
            return;
        }

        SsoVerifiedDomain::query()->create([
            'tenant_id' => $tenantId,
            'sso_connection_id' => $connection->getKey(),
            'domain' => $domain,
        ]);
    }

    private function domainTaken(string $domain): ValidationException
    {
        return ValidationException::withMessages([
            'allowed_domains' => __('i18n.backend.actions.sso.verify_sso_domain_action.domain_taken', ['domain' => $domain]),
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function assertDomainIsUnproven(string $tenantId, string $domain): void
    {
        $isProvenElsewhere = SsoVerifiedDomain::withoutTenantScope()
            ->claimedOutside($tenantId, $domain)
            ->exists();

        if (!$isProvenElsewhere) {
            return;
        }

        Log::info('A single sign-on domain was refused because another tenant proved it.', ['tenant_id' => $tenantId, 'domain' => $domain]);

        throw $this->domainTaken($domain);
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
