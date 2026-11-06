<?php

declare(strict_types=1);

use App\Support\Modules\ModuleRegistry;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    /** @var callable(list<string>):void */
    $this->registryCarrying = static function (array $names): void {
        app()->instance(ModuleRegistry::class, new class($names) extends ModuleRegistry
        {
            /**
             * @param  list<string>  $names
             */
            public function __construct(private readonly array $names) {}

            /**
             * @return list<string>
             */
            public function all(): array
            {
                return $this->names;
            }
        });
    };
});

afterEach(function (): void {
    app()->forgetInstance(ModuleRegistry::class);
});

it('refuses to register a package that is not an installed module before it migrates anything', function (): void {
    $this->artisan('modules:register', ['package' => 'laravel/framework'])
        ->expectsOutputToContain('Das Paket laravel/framework ist kein installiertes Modul.')
        ->assertFailed();
});

it('treats unregistering a module that is not registered as done', function (): void {
    ($this->registryCarrying)([]);

    $this->artisan('modules:unregister', ['package' => 'nubos/documents'])
        ->expectsOutputToContain('Das Modul nubos/documents ist nicht registriert.')
        ->assertSuccessful();
});
