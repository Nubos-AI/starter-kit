<?php

declare(strict_types=1);

use App\Http\Responses\Auth\FailedPasswordResetLinkRequestResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('answers a failed reset link request for a json client with the neutral success message', function (): void {
    $request = Request::create('/forgot-password', 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json']);

    $response = (new FailedPasswordResetLinkRequestResponse('passwords.user'))->toResponse($request);

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['message' => trans(Password::RESET_LINK_SENT)]);
});

it('redirects a web client back with the neutral status and leaks no field error', function (): void {
    $request = Request::create('/forgot-password', 'POST');
    $request->setLaravelSession(app('session.store'));
    app('url')->setRequest($request);

    $response = (new FailedPasswordResetLinkRequestResponse('passwords.user'))->toResponse($request);

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($response->getSession()->get('status'))->toBe(trans(Password::RESET_LINK_SENT))
        ->and($response->getSession()->get('errors'))->toBeNull();
});
