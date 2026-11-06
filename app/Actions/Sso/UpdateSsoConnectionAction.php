<?php

declare(strict_types=1);

namespace App\Actions\Sso;

use App\Enums\Authorization\RoleScope;
use App\Exceptions\Sso\OidcProviderException;
use App\Models\SsoConnection;
use App\Support\Audit\AdminArtifactAuditor;
use App\Support\Sso\OidcProviderClient;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class UpdateSsoConnectionAction
{
    private string $domainPattern = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    public function __construct(
        private readonly AdminArtifactAuditor $auditor,
        private readonly OidcProviderClient $provider,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     * @throws Throwable
     */
    public function execute(string $tenantId, array $data): SsoConnection
    {
        $data['allowed_domains'] = $this->normalizedDomains($data['allowed_domains'] ?? []);

        $validated = Validator::make($data, [
            'is_enabled' => ['required', 'boolean'],
            'is_enforced' => ['required', 'boolean'],
            'is_jit_enabled' => ['required', 'boolean'],
            'issuer_url' => ['nullable', 'required_if_accepted:is_enabled', 'string', 'url', 'starts_with:https://', 'max:2048'],
            'client_id' => ['nullable', 'required_if_accepted:is_enabled', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:2048'],
            'allowed_domains' => ['array', 'required_if_accepted:is_enabled'],
            'default_role_id' => [
                'nullable',
                'required_if_accepted:is_jit_enabled',
                'string',
                'ulid',
                Rule::exists('roles', 'id')->where(static fn (Builder $query): Builder => $query
                    ->where('tenant_id', $tenantId)
                    ->where('scope', RoleScope::Tenant->value)
                    ->whereNull('authority')),
            ],
        ])->validate();

        $connection = SsoConnection::query()->firstOrNew(['tenant_id' => $tenantId]);
        $secret = is_string($validated['client_secret'] ?? null) && $validated['client_secret'] !== '' ? $validated['client_secret'] : null;

        if ((bool) $validated['is_enabled'] && $secret === null && $connection->getRawOriginal('client_secret') === null) {
            throw ValidationException::withMessages(['client_secret' => __('i18n.backend.actions.sso.update_sso_connection_action.secret_required')]);
        }

        $this->assertDomainsAreFree($tenantId, $validated['allowed_domains']);

        $issuerUrl = (bool) $validated['is_enabled']
            ? $this->discoveredIssuer($tenantId, (string) $validated['issuer_url'])
            : $validated['issuer_url'] ?? null;

        $wasEnforced = (bool) $connection->getOriginal('is_enforced');

        $connection->fill([
            'tenant_id' => $tenantId,
            'default_role_id' => $validated['default_role_id'] ?? null,
            'is_enabled' => (bool) $validated['is_enabled'],
            'is_enforced' => (bool) $validated['is_enforced'],
            'is_jit_enabled' => (bool) $validated['is_jit_enabled'],
            'issuer_url' => $issuerUrl,
            'client_id' => $validated['client_id'] ?? null,
            'allowed_domains' => $validated['allowed_domains'],
            'verified_domains' => array_values(array_intersect($connection->verified_domains ?? [], $validated['allowed_domains'])),
        ]);

        if ($secret !== null) {
            $connection->fill(['client_secret' => $secret]);
        }

        $hasNewCredentials = $connection->isDirty(['issuer_url', 'client_id', 'client_secret']);

        if ($hasNewCredentials) {
            $connection->fill(['login_verified_at' => null, 'login_verified_fingerprint' => null]);
        }

        $this->assertEnforcementIsProven($connection, $tenantId, $wasEnforced, $hasNewCredentials);

        return DB::transaction(function () use ($connection, $tenantId): SsoConnection {
            $hasNewSecret = $connection->isDirty('client_secret');
            $connection->save();

            $this->auditor->recordEvent($connection, 'sso.connection_updated', [
                ...$connection->only(['is_enabled', 'is_enforced', 'is_jit_enabled', 'issuer_url', 'client_id', 'allowed_domains', 'verified_domains', 'default_role_id']),
                'client_secret_changed' => $hasNewSecret,
            ], $tenantId);

            Log::info('A single sign-on connection was saved.', ['tenant_id' => $tenantId, 'is_enabled' => $connection->is_enabled, 'is_enforced' => $connection->is_enforced]);

            return $connection;
        });
    }

    /**
     * @throws ValidationException
     */
    private function discoveredIssuer(string $tenantId, string $issuerUrl): string
    {
        try {
            return $this->provider->metadata($issuerUrl)->issuer;
        } catch (OidcProviderException $exception) {
            Log::info('A single sign-on connection was refused because its discovery failed.', ['tenant_id' => $tenantId, 'reason' => $exception->reason]);

            throw ValidationException::withMessages(['issuer_url' => $exception->userMessage()]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertEnforcementIsProven(SsoConnection $connection, string $tenantId, bool $wasEnforced, bool $hasNewCredentials): void
    {
        $isKeptLegacyEnforcement = $wasEnforced && !$hasNewCredentials;

        if (!$connection->is_enforced || $isKeptLegacyEnforcement || $connection->hasCurrentLoginProof()) {
            return;
        }

        Log::info('A single sign-on enforcement was refused because no current login proof exists.', ['tenant_id' => $tenantId, 'connection_id' => $connection->getKey()]);

        throw ValidationException::withMessages([
            'is_enforced' => __('i18n.backend.actions.sso.update_sso_connection_action.enforce_requires_login_proof'),
        ]);
    }

    /**
     * @return list<string>|mixed
     */
    private function normalizedDomains(mixed $domains): mixed
    {
        if (!is_array($domains)) {
            return $domains;
        }

        return collect($domains)
            ->map(static fn (mixed $domain): mixed => is_string($domain) ? Str::of($domain)->trim()->lower()->ltrim('@')->toString() : $domain)
            ->filter(static fn (mixed $domain): bool => $domain !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $domains
     *
     * @throws ValidationException
     */
    private function assertDomainsAreFree(string $tenantId, array $domains): void
    {
        foreach ($domains as $domain) {
            if (!is_string($domain) || preg_match($this->domainPattern, $domain) !== 1) {
                throw ValidationException::withMessages([
                    'allowed_domains' => __('i18n.backend.actions.sso.update_sso_connection_action.domain_invalid', ['domain' => is_string($domain) ? $domain : '']),
                ]);
            }

            $isClaimed = SsoConnection::withoutTenantScope()
                ->where('sso_connections.tenant_id', '!=', $tenantId)
                ->whereJsonContains('sso_connections.verified_domains', $domain)
                ->exists();

            if ($isClaimed) {
                throw ValidationException::withMessages([
                    'allowed_domains' => __('i18n.backend.actions.sso.update_sso_connection_action.domain_taken', ['domain' => $domain]),
                ]);
            }
        }
    }
}
