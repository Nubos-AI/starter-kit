<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Sso\SsoLoginProofStatus;
use App\Policies\Sso\SsoConnectionPolicy;
use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\SsoConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $default_role_id
 * @property bool $is_enabled
 * @property bool $is_enforced
 * @property bool $is_jit_enabled
 * @property string|null $issuer_url
 * @property string|null $client_id
 * @property string|null $client_secret
 * @property list<string> $allowed_domains
 * @property list<string> $verified_domains
 * @property Carbon|null $login_verified_at
 * @property string|null $login_verified_fingerprint
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[ScopedBy([TenantScope::class])]
#[UsePolicy(SsoConnectionPolicy::class)]
#[Hidden(['client_secret'])]
#[Fillable(
    [
        'tenant_id',
        'default_role_id',
        'is_enabled',
        'is_enforced',
        'is_jit_enabled',
        'issuer_url',
        'client_id',
        'client_secret',
        'allowed_domains',
        'verified_domains',
        'login_verified_at',
        'login_verified_fingerprint',
    ]
)]
class SsoConnection extends Model
{
    /** @use HasFactory<SsoConnectionFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;
    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_enforced' => 'boolean',
            'is_jit_enabled' => 'boolean',
            'client_secret' => 'encrypted',
            'allowed_domains' => 'array',
            'verified_domains' => 'array',
            'login_verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function defaultRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'default_role_id');
    }

    public function claimsEmail(string $email): bool
    {
        $domain = Str::lower(Str::after($email, '@'));

        if (!str_contains($email, '@') || $domain === '') {
            return false;
        }

        return in_array($domain, $this->allowed_domains ?? [], true)
            && in_array($domain, $this->verified_domains ?? [], true);
    }

    /**
     * @return array{name: string, value: string}
     */
    public function domainProofRecord(string $domain): array
    {
        $token = hash_hmac('sha256', "sso-domain:{$this->tenant_id}:{$domain}", (string) config('app.key'));

        return ['name' => "_nubos-sso.{$domain}", 'value' => "nubos-sso-verification={$token}"];
    }

    public function isReadyForSignIn(): bool
    {
        return $this->is_enabled
            && $this->issuer_url !== null
            && $this->client_id !== null
            && $this->getRawOriginal('client_secret') !== null;
    }

    public function loginFingerprint(): string
    {
        $credentials = implode("\n", [
            (string) $this->issuer_url,
            (string) $this->client_id,
            (string) $this->client_secret,
            route('sso.callback'),
        ]);

        return hash_hmac('sha256', $credentials, (string) config('app.key'));
    }

    public function loginProofStatus(): SsoLoginProofStatus
    {
        if ($this->login_verified_at === null || $this->login_verified_fingerprint === null) {
            return SsoLoginProofStatus::Missing;
        }

        return hash_equals($this->login_verified_fingerprint, $this->loginFingerprint())
            ? SsoLoginProofStatus::Verified
            : SsoLoginProofStatus::Outdated;
    }

    public function hasCurrentLoginProof(): bool
    {
        return $this->loginProofStatus() === SsoLoginProofStatus::Verified;
    }
}
