<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\NullEngine;
use Monolog\Handler\NullHandler;
use Monolog\Logger as MonologLogger;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('keeps the search index out of reach so a saved record never calls meilisearch', function (): void {
    expect(app(EngineManager::class)->engine())->toBeInstanceOf(NullEngine::class);
});

it('writes no test log line into the log file of the running application', function (): void {
    $logger = Log::driver()->getLogger();

    expect($logger)->toBeInstanceOf(MonologLogger::class);

    /** @var MonologLogger $logger */
    $handlers = array_map(static fn (object $handler): string => $handler::class, $logger->getHandlers());

    expect($handlers)->toBe([NullHandler::class]);
});
