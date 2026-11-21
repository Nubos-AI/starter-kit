<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\MiddlewarePipeline;
use Tests\Support\ModelStub;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    config(['app.url' => 'https://pinned.nubos.test']);

    URL::setRequest(Request::create('http://evil.example/register', 'POST'));

    $this->user = ModelStub::make(User::class, [
        'id' => ModelStub::ulid('victim'),
        'email' => 'victim@corp.example',
    ]);

    $this->mailedUrl = fn (): string => (string) (new VerifyEmail)->toMail($this->user)->actionUrl;

    $this->verify = static fn (string $url): Response => MiddlewarePipeline::through([ValidateSignature::class.':relative'])
        ->send(Request::create($url), static fn (): Response => new Response('verified'));
});

it('pins the verification link to the configured application url instead of the request host', function (): void {
    $url = ($this->mailedUrl)();

    expect($url)->toStartWith('https://pinned.nubos.test/email/verify/'.ModelStub::ulid('victim').'/')
        ->and($url)->not->toContain('evil.example');
});

it('signs the verification link relative to the path so the host is not part of the signature', function (): void {
    $path = (string) parse_url(($this->mailedUrl)(), PHP_URL_PATH);
    $query = (string) parse_url(($this->mailedUrl)(), PHP_URL_QUERY);

    expect(($this->verify)("https://pinned.nubos.test{$path}?{$query}")->getContent())->toBe('verified');
});

it('refuses a verification link whose signed path was tampered with', function (): void {
    $url = str_replace(sha1('victim@corp.example'), sha1('attacker@evil.example'), ($this->mailedUrl)());

    expect(fn () => ($this->verify)($url))->toThrow(HttpException::class);
});

it('checks the verification route against the relative signature only', function (): void {
    $middleware = RouteShape::named('verification.verify')->declaredMiddleware();

    expect($middleware)->toContain('signed:relative')
        ->and($middleware)->not->toContain('signed');
});
