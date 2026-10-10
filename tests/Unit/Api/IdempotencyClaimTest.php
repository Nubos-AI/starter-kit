<?php

declare(strict_types=1);

use App\Enums\Api\ApiErrorCode;
use App\Http\Middleware\Api\EnforceIdempotencyKey;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\AccessContext;
use Tests\Support\QueryShape;
use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->middleware = new EnforceIdempotencyKey;
    $this->migration = 'database/migrations/0001_01_01_000044_create_idempotency_keys_table.php';

    /** @var callable(?string, ?User, string):Request */
    $this->requestWith = static function (?string $key, ?User $user = null, string $bearer = '17|plain-text-secret'): Request {
        $request = Request::create('/api/v1/companies', 'POST', [], [], [], [], '{"data":{}}');

        if ($key !== null) {
            $request->headers->set('Idempotency-Key', $key);
        }

        $request->headers->set('Authorization', 'Bearer '.$bearer);
        $request->setUserResolver(static fn (): ?User => $user);

        return $request;
    };

    $this->reached = static fn (): Response => new Response('reached');
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('scopes a claim to the tenant and the token so the same key never collides across clients', function (): void {
    $shape = SchemaShape::ofMigration($this->migration);

    expect($shape->has('alter table "idempotency_keys" add constraint "uniq_idempotency_keys_claim" unique ("tenant_id", "access_token_id", "idempotency_key")'))
        ->toBeTrue()
        ->and($shape->columnsOf('idempotency_keys'))
        ->toBe([
            'id',
            'tenant_id',
            'access_token_id',
            'idempotency_key',
            'request_body_hash',
            'status',
            'response_status',
            'response_body',
            'locked_at',
            'created_at',
        ]);
});

it('drops a claim with the tenant and with the token it was made for', function (): void {
    $shape = SchemaShape::ofMigration($this->migration);

    expect($shape->hasForeignKey('idempotency_keys', 'tenant_id', 'tenants', 'cascade'))->toBeTrue()
        ->and($shape->hasForeignKey('idempotency_keys', 'access_token_id', 'personal_access_tokens', 'cascade'))->toBeTrue();
});

it('drops the whole table again on the way down', function (): void {
    expect(SchemaShape::ofMigration($this->migration, 'down')->has('drop table if exists "idempotency_keys"'))->toBeTrue();
});

it('runs a request without an idempotency key straight through and claims nothing', function (): void {
    $request = ($this->requestWith)(null, AccessContext::user($this->tenant));

    $reachedTheDatabase = QueryShape::attemptedBy(fn (): Response => $this->middleware->handle($request, $this->reached));

    expect($reachedTheDatabase)->toBeNull()
        ->and($this->middleware->handle($request, $this->reached)->getContent())->toBe('reached');
});

it('rejects an unusable idempotency key with 422 and claims nothing', function (string $key): void {
    $request = ($this->requestWith)($key, AccessContext::user($this->tenant));

    $reachedTheDatabase = QueryShape::attemptedBy(fn (): Response => $this->middleware->handle($request, $this->reached));
    $response = $this->middleware->handle($request, $this->reached);

    expect($reachedTheDatabase)->toBeNull()
        ->and($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['errors'][0]['code'])->toBe(ApiErrorCode::ValidationFailed->value);
})->with([
    'only whitespace' => ['   '],
    'too long' => [str_repeat('k', 256)],
]);

it('resolves the claiming token from the presented bearer and never from the session', function (): void {
    $request = ($this->requestWith)('key-1', AccessContext::user($this->tenant));

    $shape = QueryShape::attemptedBy(fn (): Response => $this->middleware->handle($request, $this->reached));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('personal_access_tokens'))->toBeTrue()
        ->and($shape->bindings)->toContain('17');
});

it('refuses to claim for a session that presents no bearer token', function (): void {
    $request = ($this->requestWith)('key-1', AccessContext::user($this->tenant));
    $request->headers->remove('Authorization');

    $reachedTheDatabase = QueryShape::attemptedBy(function () use ($request): void {
        try {
            $this->middleware->handle($request, $this->reached);
        } catch (AuthenticationException) {
            return;
        }
    });

    expect($reachedTheDatabase)->toBeNull()
        ->and(fn (): Response => $this->middleware->handle($request, $this->reached))
        ->toThrow(AuthenticationException::class);
});
