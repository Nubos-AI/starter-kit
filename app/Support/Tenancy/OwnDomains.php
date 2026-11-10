<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Enums\Tenancy\DomainPointing;
use App\Enums\Tenancy\DomainProofPurpose;
use App\Exceptions\Tenancy\DnsLookupFailedException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OwnDomains
{
    public function __construct(
        private readonly DnsLookup $dns,
        private readonly WelcomeTenantResolver $welcome,
    ) {}

    /**
     * @throws ValidationException
     */
    public function normalize(mixed $value): ?string
    {
        $domain = Str::of(is_string($value) ? $value : '')->trim()->lower()->rtrim('.')->toString();
        $domain = $domain === '' ? null : $domain;

        Validator::make(['domain' => $domain], [
            'domain' => ['nullable', 'string', 'max:253', 'regex:'.WelcomeTenantResolver::$hostPattern],
        ])->validate();

        if ($domain !== null && $this->welcome->isPlatformHost($domain)) {
            throw ValidationException::withMessages(['domain' => __('i18n.backend.actions.tenancy.update_tenant_domain_action.platform_domain')]);
        }

        return $domain;
    }

    public function taken(): ValidationException
    {
        return ValidationException::withMessages(['domain' => __('i18n.backend.actions.tenancy.update_tenant_domain_action.domain_taken')]);
    }

    public function missing(): ValidationException
    {
        return ValidationException::withMessages(['domain' => __('i18n.backend.actions.tenancy.verify_tenant_domain_action.no_domain')]);
    }

    public function refusal(DomainPointing $pointing, string $domain, string $target, string $tenantId): ValidationException
    {
        if ($pointing !== DomainPointing::Unproven) {
            return ValidationException::withMessages(['domain' => __('i18n.backend.actions.tenancy.verify_tenant_domain_action.not_pointing', ['value1' => $target])]);
        }

        $proof = DomainProof::recordFor(DomainProofPurpose::Site, $tenantId, $domain);

        return ValidationException::withMessages(['domain' => __('i18n.backend.actions.tenancy.verify_tenant_domain_action.proof_missing', ['name' => $proof['name'], 'value' => $proof['value']])]);
    }

    /**
     * @return array{recordName: string, recordValue: string}|null
     */
    public function proofOf(string $tenantId, ?string $domain): ?array
    {
        if ($domain === null) {
            return null;
        }

        $proof = DomainProof::recordFor(DomainProofPurpose::Site, $tenantId, $domain);

        return ['recordName' => $proof['name'], 'recordValue' => $proof['value']];
    }

    /**
     * @param  array<string, mixed>  $logContext
     *
     * @throws ValidationException
     */
    public function pointingOf(string $domain, string $target, string $tenantId, array $logContext): DomainPointing
    {
        try {
            return $this->pointsAt($domain, $target, $tenantId);
        } catch (DnsLookupFailedException $exception) {
            Log::warning('Own domain could not be verified.', [...$logContext, 'host' => $exception->host]);

            throw ValidationException::withMessages(['domain' => __('i18n.backend.actions.tenancy.verify_tenant_domain_action.dns_unavailable')]);
        }
    }

    /**
     * @throws DnsLookupFailedException
     */
    private function pointsAt(string $domain, string $target, string $tenantId): DomainPointing
    {
        $cname = $this->dns->cnameOf($domain);

        if ($cname !== null) {
            return Str::of($cname)->lower()->rtrim('.')->toString() === $target ? DomainPointing::Pointing : DomainPointing::Elsewhere;
        }

        $addresses = $this->dns->addressesOf($domain);

        if ($addresses === [] || array_diff($addresses, $this->dns->addressesOf($target)) !== []) {
            return DomainPointing::Elsewhere;
        }

        $proof = DomainProof::recordFor(DomainProofPurpose::Site, $tenantId, $domain);

        return in_array($proof['value'], $this->dns->txtOf($proof['name']), true) ? DomainPointing::Pointing : DomainPointing::Unproven;
    }
}
