<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Illuminate\Support\Env;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->template = (string) file_get_contents(base_path('.env.example'));

    $this->sqliteDefaults = ['DB_HOST=127.0.0.1', 'DB_PORT=3306', 'DB_DATABASE=laravel', 'DB_USERNAME=root', 'DB_PASSWORD='];

    $this->rewrittenByInstaller = function (string $environment, string $database): string {
        $environment = (string) preg_replace('/DB_CONNECTION=.*/', "DB_CONNECTION={$database}", $environment);
        $commented = array_map(static fn (string $default): string => "# {$default}", $this->sqliteDefaults);

        if ($database === 'sqlite') {
            return str_contains($environment, '# DB_HOST=127.0.0.1')
                ? $environment
                : str_replace($this->sqliteDefaults, $commented, $environment);
        }

        $environment = str_replace($commented, $this->sqliteDefaults, $environment);

        return str_replace(['DB_PORT=3306', 'DB_DATABASE=laravel'], ['DB_PORT=5432', 'DB_DATABASE=meine_app'], $environment);
    };

    $this->defaultConnection = function (): string {
        $repository = Env::getRepository();
        $configured = $repository->get('DB_CONNECTION');
        $repository->clear('DB_CONNECTION');

        try {
            /** @var array{default: string} $database */
            $database = require config_path('database.php');
        } finally {
            if (is_string($configured)) {
                $repository->set('DB_CONNECTION', $configured);
            }
        }

        return $database['default'];
    };
});

it('keeps the postgres settings of the env template when the laravel installer switches a starter kit to sqlite', function (string $database): void {
    $environment = Dotenv::parse(($this->rewrittenByInstaller)($this->template, $database));

    expect($environment)->not->toHaveKey('DB_CONNECTION')
        ->and(($this->defaultConnection)())->toBe('pgsql')
        ->and($environment['DB_HOST'] ?? null)->toBe('pgsql')
        ->and($environment['DB_PORT'] ?? null)->toBe('5432')
        ->and($environment['DB_DATABASE'] ?? null)->toBe('nubos')
        ->and($environment['DB_USERNAME'] ?? null)->toBe('sail')
        ->and($environment['DB_PASSWORD'] ?? null)->toBe('password');
})->with([
    'without a database option' => 'sqlite',
    'with the postgres option' => 'pgsql',
]);

it('leaves every forwarded port to the default of compose.yaml so a second installation can move it through the environment', function (): void {
    expect(array_keys(Dotenv::parse($this->template)))->each->not->toStartWith('FORWARD_');
});

it('leaves the reverb service to the crm installer instead of restarting it endlessly without laravel/reverb', function (): void {
    /** @var array{services: array<string, mixed>} $compose */
    $compose = Yaml::parseFile(base_path('compose.yaml'));

    expect($compose['services'])->not->toHaveKey('reverb');
});

it('publishes the ports of the backing services on the loopback interface only, so nobody on the same network reaches the database, the search index or temporal', function (): void {
    /** @var array{services: array<string, array{ports?: list<string>}>} $compose */
    $compose = Yaml::parseFile(base_path('compose.yaml'));

    $exposed = collect($compose['services'])
        ->except('laravel.test')
        ->flatMap(static fn (array $service): array => $service['ports'] ?? [])
        ->reject(static fn (string $port): bool => str_starts_with($port, '127.0.0.1:'))
        ->values()
        ->all();

    expect($exposed)->toBe([]);
});
