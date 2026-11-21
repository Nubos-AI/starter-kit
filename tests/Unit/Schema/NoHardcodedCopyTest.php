<?php

declare(strict_types=1);

use App\Support\Conventions\HardcodedCopyScanner;
use Tests\Support\ModuleRoots;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/copy-scanner-'.bin2hex(random_bytes(6));

    mkdir($this->directory);

    $this->scanFile = function (string $name, string $source): array {
        file_put_contents("{$this->directory}/{$name}", $source);

        return app(HardcodedCopyScanner::class)->scan($this->directory);
    };
});

afterEach(function (): void {
    foreach (glob("{$this->directory}/*") ?: [] as $file) {
        unlink($file);
    }

    rmdir($this->directory);
});

test('no PHP file of the core carries German copy outside the translation catalogue', function (): void {
    expect(app(HardcodedCopyScanner::class)->scan(base_path('app')))->toBe([]);
});

test('no PHP file of a package carries German copy outside the translation catalogue', function (): void {
    $offenders = collect(ModuleRoots::directories('src'))
        ->flatMap(static fn (string $directory): array => app(HardcodedCopyScanner::class)->scan($directory))
        ->all();

    expect($offenders)->toBe([]);
});

test('the scanner reports German copy in a toast, a console line and an interpolated message', function (): void {
    $offenders = ($this->scanFile)('Offender.php', <<<'PHP'
        <?php

        class Offender
        {
            public function run(string $name): void
            {
                Inertia::flash('toast', 'Gespeichert. Bitte prüfen Sie die Angaben.');
                $this->info('Der Mandant wurde gelöscht');
                $this->error("Das Modul {$name} ist nicht registriert.");
            }
        }
        PHP);

    expect($offenders)->toHaveCount(3)
        ->and($offenders[0])->toContain('Offender.php:7');
});

test('the scanner leaves translation keys, English text and technical strings alone', function (): void {
    $offenders = ($this->scanFile)('Allowed.php', <<<'PHP'
        <?php

        class Allowed
        {
            public function run(string $name): string
            {
                $this->info("The module {$name} is not registered.");
                Log::warning('Tenant account left because it is no longer available.');

                return __('application::i18n.backend.validation.tenant_account_unavailable', ['name' => $name]);
            }
        }
        PHP);

    expect($offenders)->toBe([]);
});

test('the scanner skips the files whose German strings are a fixed external format', function (): void {
    $offenders = ($this->scanFile)('DatevBookingBatch.php', <<<'PHP'
        <?php

        class DatevBookingBatch
        {
            private array $columns = ['BU-Schlüssel', 'Fälligkeit'];
        }
        PHP);

    expect($offenders)->toBe([]);
});
