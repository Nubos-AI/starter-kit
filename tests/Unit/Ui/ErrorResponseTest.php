<?php

declare(strict_types=1);

use App\Models\CustomRecord;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Support\Header;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->handler = app(ExceptionHandler::class);

    /** @var callable(array<string, string>):Request */
    $this->uiRequest = static function (array $headers = []): Request {
        $request = Request::create('/dashboard');

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    };

    /** @var callable(Throwable, array<string, string>):Response */
    $this->renderOf = fn (Throwable $throwable, array $headers = []): Response => $this->handler->render(
        ($this->uiRequest)($headers),
        $throwable,
    );

    /** @var callable(Response):array<string, mixed> */
    $this->payloadOf = static function (Response $response): array {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    };

    /** @var callable(string):QueryException */
    $this->cancelledQuery = static function (string $sqlState): QueryException {
        $previous = new PDOException('canceling statement due to statement timeout');
        $previous->errorInfo = [$sqlState, 7, 'canceling statement due to statement timeout'];

        return new QueryException('pgsql', 'select 1', [], $previous);
    };
});

it('strips every debug key from a rejected ui request', function (): void {
    config(['app.debug' => true]);

    $payload = ($this->payloadOf)(($this->renderOf)(
        new HttpException(403, ''),
        ['Accept' => 'application/json'],
    ));

    expect($payload)->not->toHaveKey('trace')
        ->and($payload)->not->toHaveKey('exception')
        ->and($payload)->not->toHaveKey('file')
        ->and($payload)->not->toHaveKey('line');
});

it('replaces the message of an unexpected failure with a german sentence', function (): void {
    config(['app.debug' => true]);

    $response = ($this->renderOf)(
        new RuntimeException('SENSITIVE_UI_LEAK_marker_7c1d'),
        ['Accept' => 'application/json'],
    );

    expect($response->getStatusCode())->toBe(500)
        ->and(($this->payloadOf)($response)['message'])
        ->toBe('Es ist ein unerwarteter Fehler aufgetreten. Bitte versuchen Sie es später erneut.')
        ->and((string) $response->getContent())->not->toContain('SENSITIVE_UI_LEAK_marker_7c1d')
        ->and((string) $response->getContent())->not->toContain('RuntimeException');
});

it('keeps a reason the application authored itself', function (): void {
    config(['app.debug' => true]);

    $payload = ($this->payloadOf)(($this->renderOf)(
        new HttpException(403, 'The approval stage is locked for your role.'),
        ['Accept' => 'application/json'],
    ));

    expect($payload['message'])->toBe('The approval stage is locked for your role.')
        ->and($payload)->not->toHaveKey('trace')
        ->and($payload)->not->toHaveKey('file');
});

it('never names the eloquent class behind a missing model', function (): void {
    config(['app.debug' => true]);

    $missing = (new ModelNotFoundException)->setModel(CustomRecord::class);

    $response = ($this->renderOf)($missing, ['Accept' => 'application/json']);

    expect($response->getStatusCode())->toBe(404)
        ->and((string) $response->getContent())->not->toContain('App\\Models')
        ->and((string) $response->getContent())->not->toContain('CustomRecord')
        ->and(($this->payloadOf)($response)['message'])->toBe('Die angeforderte Seite wurde nicht gefunden.');
});

it('renders the application error page for an inertia visit instead of the debug page', function (): void {
    config(['app.debug' => true]);

    $response = ($this->renderOf)(
        new RuntimeException('SENSITIVE_UI_LEAK_marker_7c1d'),
        [Header::INERTIA => 'true'],
    );

    $payload = ($this->payloadOf)($response);

    expect($response->getStatusCode())->toBe(500)
        ->and($payload['component'])->toBe('errors/Error')
        ->and($payload['props']['status'])->toBe(500)
        ->and((string) $response->getContent())->not->toContain('SENSITIVE_UI_LEAK_marker_7c1d');
});

it('renders the application error page for a forbidden, a missing and an expired inertia visit', function (): void {
    foreach ([403, 404, 419] as $status) {
        $response = ($this->renderOf)(
            new HttpException($status, ''),
            [Header::INERTIA => 'true'],
        );

        expect($response->getStatusCode())->toBe($status)
            ->and(($this->payloadOf)($response)['props']['status'])->toBe($status);
    }
});

it('answers a cancelled query with a readable german sentence and a service status', function (): void {
    Log::spy();

    $response = ($this->renderOf)(
        ($this->cancelledQuery)('57014'),
        ['Accept' => 'application/json'],
    );

    expect($response->getStatusCode())->toBe(503)
        ->and(($this->payloadOf)($response)['message'])
        ->toBe('Die Abfrage hat zu lange gedauert. Bitte schränken Sie Filter oder Suche ein.');
});

it('records a cancelled query in the log so it stops being invisible', function (): void {
    Log::spy();

    ($this->renderOf)(($this->cancelledQuery)('57014'), ['Accept' => 'application/json']);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(static fn (string $message): bool => str_contains($message, 'cancelled'));
});

it('leaves a query failure that is not a timeout on the generic error path', function (): void {
    config(['app.debug' => false]);

    $response = ($this->renderOf)(
        ($this->cancelledQuery)('08006'),
        ['Accept' => 'application/json'],
    );

    $payload = ($this->payloadOf)($response);

    expect($response->getStatusCode())->toBe(500)
        ->and($payload['message'])->not->toContain('select 1')
        ->and($payload)->not->toHaveKey('trace')
        ->and($payload)->not->toHaveKey('exception');
});

it('hands a cancelled query on an inertia visit a plain message instead of an error page', function (): void {
    Log::spy();

    $response = ($this->renderOf)(($this->cancelledQuery)('57014'), [Header::INERTIA => 'true']);

    $payload = ($this->payloadOf)($response);

    expect($response->getStatusCode())->toBe(503)
        ->and($payload)->not->toHaveKey('component')
        ->and($payload['message'])->toBe('Die Abfrage hat zu lange gedauert. Bitte schränken Sie Filter oder Suche ein.');
});
