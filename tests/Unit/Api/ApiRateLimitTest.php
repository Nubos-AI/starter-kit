<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\AccessContext;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    /** @var callable(?string, ?User):Request */
    $this->requestWith = static function (?string $bearer, ?User $user): Request {
        $request = Request::create('/api/v1/companies', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.9']);

        if ($bearer !== null) {
            $request->headers->set('Authorization', 'Bearer '.$bearer);
        }

        $request->setUserResolver(static fn (): ?User => $user);

        return $request;
    };

    /** @var callable(Request):list<Limit> */
    $this->limitsFor = static function (Request $request): array {
        $resolved = RateLimiter::limiter('api')($request);

        return is_array($resolved) ? array_values($resolved) : [$resolved];
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('gives every token its own bucket so two tokens of one user never share a budget', function (): void {
    $user = AccessContext::user($this->tenant);

    $first = ($this->limitsFor)(($this->requestWith)('17|first-secret', $user));
    $second = ($this->limitsFor)(($this->requestWith)('18|second-secret', $user));

    expect($first[0]->key)->not->toBe($second[0]->key)
        ->and($first[0]->key)->toStartWith('token:');
});

it('adds a tenant wide bucket next to the token bucket', function (): void {
    $user = AccessContext::user($this->tenant);

    $limits = ($this->limitsFor)(($this->requestWith)('17|first-secret', $user));

    expect($limits)->toHaveCount(2)
        ->and($limits[1]->key)->toBe('tenant:'.$this->tenant->getKey())
        ->and($limits[0]->maxAttempts)->toBe((int) config('api.rate_limit.perMinute'))
        ->and($limits[1]->maxAttempts)->toBe((int) config('api.rate_limit.perTenantPerMinute'));
});

it('never leaks the plain bearer token into the bucket key', function (): void {
    $user = AccessContext::user($this->tenant);

    $limits = ($this->limitsFor)(($this->requestWith)('17|first-secret', $user));

    expect($limits[0]->key)->not->toContain('first-secret')
        ->and($limits[0]->key)->toBe('token:'.hash('sha256', '17|first-secret'));
});

it('falls back to the ip bucket and the anonymous budget without a bearer token', function (): void {
    $limits = ($this->limitsFor)(($this->requestWith)(null, null));

    expect($limits)->toHaveCount(1)
        ->and($limits[0]->key)->toBe('ip:203.0.113.9')
        ->and($limits[0]->maxAttempts)->toBe((int) config('api.rate_limit.anonPerMinute'));
});

it('keeps the token bucket alone when the holder carries no tenant', function (): void {
    $limits = ($this->limitsFor)(($this->requestWith)('17|first-secret', null));

    expect($limits)->toHaveCount(1)
        ->and($limits[0]->key)->toStartWith('token:');
});

it('pins the api rate limiter store to a shared cache instead of the process memory', function (): void {
    expect(config('api.rate_limit.store'))->toBe('redis');
});
