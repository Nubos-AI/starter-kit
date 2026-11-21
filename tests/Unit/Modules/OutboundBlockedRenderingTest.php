<?php

declare(strict_types=1);

use App\Enums\Api\ApiErrorCode;
use App\Enums\Ui\ToastType;
use App\Exceptions\Modules\OutboundBlockedException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Support\Header;
use Inertia\Support\SessionKey;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->notice = 'Ausgehende Verbindungen sind in dieser Umgebung gesperrt. Es wurde nichts gesendet.';

    $this->rendered = function (Request $request): Response {
        session()->setPreviousUrl('https://nubos.test/acme/records/deals/01');
        $request->setLaravelSession(session()->driver());
        app()->instance('request', $request);

        return app(ExceptionHandler::class)->render($request, new OutboundBlockedException('+49 170 0000000'));
    };
});

it('answers a blocked outbound call on the api with a conflict in the error envelope instead of a server error', function (): void {
    $request = Request::create('/api/v1/companies', 'POST');
    $request->headers->set('Accept', 'application/json');

    $response = ($this->rendered)($request);
    $payload = json_decode((string) $response->getContent(), true);

    expect($response->getStatusCode())->toBe(409)
        ->and($payload['errors'][0]['code'])->toBe(ApiErrorCode::Conflict->value)
        ->and($payload['errors'][0]['detail'])->toBe($this->notice);
});

it('sends the user of a page back with an error toast naming the blocked outbound call', function (): void {
    $request = Request::create('/acme/records/deals/01/sms', 'POST');
    $request->headers->set(Header::INERTIA, 'true');

    $response = ($this->rendered)($request);

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($response->getTargetUrl())->toBe('https://nubos.test/acme/records/deals/01')
        ->and(session()->get(SessionKey::FLASH_DATA)['toast'] ?? null)->toBe(['type' => ToastType::Error->value, 'message' => $this->notice]);
});

it('answers a blocked outbound call from a json request of the ui with the notice instead of a server error', function (): void {
    $request = Request::create('/acme/records/deals/01/sms', 'POST');
    $request->headers->set('Accept', 'application/json');

    $response = ($this->rendered)($request);

    expect($response->getStatusCode())->toBe(409)
        ->and(json_decode((string) $response->getContent(), true))->toBe(['message' => $this->notice]);
});
