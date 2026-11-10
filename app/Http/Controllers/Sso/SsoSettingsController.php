<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sso;

use App\Actions\Scim\RotateScimTokenAction;
use App\Actions\Sso\StartSsoLoginTestAction;
use App\Actions\Sso\TestSsoConnectionAction;
use App\Actions\Sso\UpdateSsoConnectionAction;
use App\Actions\Sso\VerifySsoDomainAction;
use App\Enums\Authorization\RoleScope;
use App\Enums\Sso\SsoLoginProofStatus;
use App\Enums\Ui\ToastType;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\Role;
use App\Models\ScimToken;
use App\Models\SsoConnection;
use App\Support\Scim\ScimAccessGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

class SsoSettingsController extends Controller
{
    /**
     * @var list<string>
     */
    private array $connectionFields = [
        'is_enabled',
        'is_enforced',
        'is_jit_enabled',
        'issuer_url',
        'client_id',
        'client_secret',
        'allowed_domains',
        'default_role_id',
    ];

    public function __construct(
        private readonly UpdateSsoConnectionAction $updateConnection,
        private readonly TestSsoConnectionAction $testConnection,
        private readonly StartSsoLoginTestAction $startLoginTest,
        private readonly RotateScimTokenAction $rotateScimToken,
        private readonly VerifySsoDomainAction $verifySsoDomain,
        private readonly ScimAccessGuard $scimAccess,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function edit(Request $request): Response
    {
        $this->authorize('manage', SsoConnection::class);

        $connection = SsoConnection::query()->first();
        $token = ScimToken::query()->first();
        $secret = $request->session()->pull('scimTokenSecret');

        return Inertia::render('sso/Settings', [
            'connection' => [
                'isEnabled' => $connection->is_enabled ?? false,
                'isEnforced' => $connection->is_enforced ?? false,
                'isJitEnabled' => $connection->is_jit_enabled ?? false,
                'issuerUrl' => $connection?->issuer_url,
                'clientId' => $connection?->client_id,
                'hasClientSecret' => $connection?->getRawOriginal('client_secret') !== null,
                'allowedDomains' => $connection->allowed_domains ?? [],
                'verifiedDomains' => $connection?->verifiedDomainNames() ?? [],
                'domainProofs' => $this->domainProofs($connection),
                'defaultRoleId' => $connection?->default_role_id,
            ],
            'loginProof' => $this->loginProof($connection),
            'roleOptions' => Role::query()
                ->where('scope', RoleScope::Tenant)
                ->whereNull('authority')
                ->orderBy('name')
                ->get()
                ->map(static fn (Role $role): array => ['value' => (string) $role->getKey(), 'label' => $role->name])
                ->all(),
            'callbackUrl' => route('sso.callback'),
            'scim' => [
                'baseUrl' => url('/scim/v2'),
                'hasToken' => $token instanceof ScimToken,
                'needsRotation' => $token instanceof ScimToken && !$this->scimAccess->hasEntitledCreator($token),
                'tokenCreatedAt' => $token?->created_at?->toIso8601String(),
                'lastUsedAt' => $token?->last_used_at?->toIso8601String(),
            ],
            'scimToken' => is_string($secret) ? $secret : null,
        ]);
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws Throwable
     */
    public function update(Request $request): RedirectResponse
    {
        $this->authorize('manage', SsoConnection::class);

        $this->updateConnection->execute($this->actingUser($request), $request->only($this->connectionFields));

        Inertia::flash('toast', ['type' => ToastType::Success->value, 'message' => __('i18n.backend.http.controllers.sso.sso_settings_controller.saved')]);

        return back();
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function test(Request $request): RedirectResponse
    {
        $this->authorize('manage', SsoConnection::class);

        $metadata = $this->testConnection->execute($request->only('issuer_url'));

        Inertia::flash('toast', ['type' => ToastType::Info->value, 'message' => __('i18n.backend.http.controllers.sso.sso_settings_controller.discovery_found', ['issuer' => $metadata->issuer])]);

        return back();
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws Throwable
     */
    public function verifyDomain(Request $request): RedirectResponse
    {
        $this->authorize('manage', SsoConnection::class);

        $this->verifySsoDomain->execute((string) $this->actingUser($request)->tenant_id, $request->only('domain'));

        Inertia::flash('toast', ['type' => ToastType::Success->value, 'message' => __('i18n.backend.http.controllers.sso.sso_settings_controller.domain_verified', ['domain' => $request->string('domain')->toString()])]);

        return back();
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function startLoginTest(Request $request): HttpResponse
    {
        $this->authorize('manage', SsoConnection::class);

        $url = $this->startLoginTest->execute($this->actingUser($request), $request->session());

        return Inertia::location($url);
    }

    /**
     * @throws AuthorizationException
     * @throws Throwable
     */
    public function rotateScimToken(Request $request): RedirectResponse
    {
        $this->authorize('manage', SsoConnection::class);

        $token = $this->rotateScimToken->execute($this->actingUser($request));

        $request->session()->flash('scimTokenSecret', $token);

        return back();
    }

    /**
     * @return array{status: string, verifiedAt: string|null, canTest: bool, testReason: string|null, canEnforce: bool, enforceReason: string|null}
     */
    private function loginProof(?SsoConnection $connection): array
    {
        $status = $connection?->loginProofStatus() ?? SsoLoginProofStatus::Missing;
        $canTest = $connection?->isReadyForSignIn() ?? false;
        $canEnforce = ($connection->is_enforced ?? false) || $status === SsoLoginProofStatus::Verified;

        return [
            'status' => $status->value,
            'verifiedAt' => $connection?->login_verified_at?->toIso8601String(),
            'canTest' => $canTest,
            'testReason' => $this->refusalUnless($canTest, 'i18n.backend.actions.sso.start_sso_login_test_action.connection_incomplete'),
            'canEnforce' => $canEnforce,
            'enforceReason' => $this->refusalUnless($canEnforce, 'i18n.backend.http.controllers.sso.sso_settings_controller.enforce_requires_login_proof'),
        ];
    }

    /**
     * @return list<array{domain: string, recordName: string, recordValue: string}>
     */
    private function domainProofs(?SsoConnection $connection): array
    {
        if (!$connection instanceof SsoConnection) {
            return [];
        }

        return array_map(static function (string $domain) use ($connection): array {
            $record = $connection->domainProofRecord($domain);

            return ['domain' => $domain, 'recordName' => $record['name'], 'recordValue' => $record['value']];
        }, $connection->allowed_domains ?? []);
    }

    private function refusalUnless(bool $isAllowed, string $translationKey): ?string
    {
        if ($isAllowed) {
            return null;
        }

        return __($translationKey);
    }
}
