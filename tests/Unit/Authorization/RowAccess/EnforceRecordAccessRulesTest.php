<?php

declare(strict_types=1);

use App\Http\Middleware\Authorization\EnforceRecordAccessRules;
use App\Support\Authorization\RowAccess\RowAccessEnforcement;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

afterEach(function (): void {
    app(RowAccessEnforcement::class)->disable();
});

it('turns row access enforcement on before the request continues and leaves it on for the rest of the request', function (): void {
    $enforcement = app(RowAccessEnforcement::class);
    $enforcement->disable();

    $enabledInsideTheChain = null;

    $response = (new EnforceRecordAccessRules($enforcement))->handle(
        Request::create('/probe', 'GET'),
        function () use ($enforcement, &$enabledInsideTheChain): Response {
            $enabledInsideTheChain = $enforcement->isEnabled();

            return new Response('reached');
        },
    );

    expect($enabledInsideTheChain)->toBeTrue()
        ->and($enforcement->isEnabled())->toBeTrue()
        ->and($response->getContent())->toBe('reached');
});
