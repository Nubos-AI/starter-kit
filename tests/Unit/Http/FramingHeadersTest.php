<?php

declare(strict_types=1);

use App\Http\Middleware\SetFramingHeaders;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    config()->set('security.frame_embeddable_routes', ['public.widgets.*']);

    $this->requestFor = static function (?string $routeName, string $url = '/probe'): Request {
        $request = Request::create($url, 'GET');

        if ($routeName !== null) {
            $route = (new Route(['GET'], 'probe', static fn (): string => 'ok'))->name($routeName);
            $request->setRouteResolver(static fn (): Route => $route);
        }

        return $request;
    };

    $this->respond = fn (Request $request, ?Response $response = null): Response => (new SetFramingHeaders)->handle(
        $request,
        static fn (): Response => $response ?? new Response('ok'),
    );
});

it('forbids every foreign site to frame an application page', function (?string $routeName): void {
    $response = ($this->respond)(($this->requestFor)($routeName));

    expect($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($response->headers->get('Content-Security-Policy'))->toBe("frame-ancestors 'self'");
})->with([
    'a named application route' => ['engine.records.show'],
    'a request without a route' => [null],
]);

it('lets a page that a module declared embeddable be framed by any site', function (): void {
    $response = ($this->respond)(($this->requestFor)('public.widgets.show'));

    expect($response->headers->has('X-Frame-Options'))->toBeFalse()
        ->and($response->headers->has('Content-Security-Policy'))->toBeFalse();
});

it('leaves a content security policy that the response already carries untouched', function (): void {
    $response = ($this->respond)(
        ($this->requestFor)('engine.records.files.show'),
        new Response('file', 200, ['Content-Security-Policy' => "sandbox; default-src 'none'"]),
    );

    expect($response->headers->get('Content-Security-Policy'))->toBe("sandbox; default-src 'none'")
        ->and($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN');
});

it('leaves a frame option that the response already carries untouched', function (): void {
    $response = ($this->respond)(
        ($this->requestFor)('engine.records.show'),
        new Response('ok', 200, ['X-Frame-Options' => 'DENY']),
    );

    expect($response->headers->get('X-Frame-Options'))->toBe('DENY');
});

it('runs on every page of the web stack and on every api response', function (string $group): void {
    expect(Router::getMiddlewareGroups()[$group])->toContain(SetFramingHeaders::class);
})->with(['web', 'api']);

it('forbids content sniffing and limits the referrer to the origin on every response', function (?string $routeName): void {
    $response = ($this->respond)(($this->requestFor)($routeName));

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
})->with([
    'an application page' => ['engine.records.show'],
    'an embeddable page' => ['public.widgets.show'],
    'a request without a route' => [null],
]);

it('keeps the stricter referrer policy a token page already set', function (): void {
    $response = ($this->respond)(
        ($this->requestFor)('invitations.show'),
        new Response('ok', 200, ['Referrer-Policy' => 'no-referrer']),
    );

    expect($response->headers->get('Referrer-Policy'))->toBe('no-referrer');
});

it('pins the browser to https only for a request that arrived over https', function (string $url, ?string $expected): void {
    $response = ($this->respond)(($this->requestFor)('engine.records.show', $url));

    expect($response->headers->get('Strict-Transport-Security'))->toBe($expected);
})->with([
    'secure' => ['https://app.nubos.test/probe', 'max-age=31536000; includeSubDomains'],
    'plain' => ['http://app.nubos.test/probe', null],
]);
