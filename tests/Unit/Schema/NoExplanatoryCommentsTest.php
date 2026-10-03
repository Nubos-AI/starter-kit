<?php

declare(strict_types=1);

use App\Support\Conventions\CommentScanner;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

test('no PHP file explains itself in prose instead of in its names', function (): void {
    $offenders = app(CommentScanner::class)->scanPhp(base_path('app'));

    expect($offenders)->toBe([]);
});

test('no frontend file explains itself in prose instead of in its names', function (): void {
    $offenders = app(CommentScanner::class)->scanFrontend(base_path('resources/js'));

    expect($offenders)->toBe([]);
});

test('no packaged file explains itself in prose instead of in its names', function (): void {
    $scanner = app(CommentScanner::class);
    $packages = glob(base_path('packages/nubos/*'), GLOB_ONLYDIR) ?: [];
    $offenders = [];

    expect($packages)->not->toBeEmpty();

    foreach ($packages as $package) {
        foreach (['src', 'database'] as $directory) {
            if (is_dir($package.'/'.$directory)) {
                $offenders = [...$offenders, ...$scanner->scanPhp($package.'/'.$directory)];
            }
        }

        if (is_dir($package.'/resources/js')) {
            $offenders = [...$offenders, ...$scanner->scanFrontend($package.'/resources/js')];
        }
    }

    expect($offenders)->toBe([]);
});

test('the scanner reports a prose docblock and a prose line comment', function (): void {
    $scanner = app(CommentScanner::class);
    $directory = sys_get_temp_dir().'/comment-scanner-'.bin2hex(random_bytes(6));

    mkdir($directory);

    file_put_contents($directory.'/Offender.php', <<<'PHP'
        <?php

        class Offender
        {
            /**
             * Explains what the reader can already see.
             */
            public function run(): void
            {
                // and so does this
            }
        }
        PHP);

    file_put_contents($directory.'/offender.ts', <<<'TS'
        // Explains what the reader can already see.
        export const answer = 42;
        TS);

    expect($scanner->scanPhp($directory))->toHaveCount(2)
        ->and($scanner->scanFrontend($directory))->toHaveCount(1);

    unlink($directory.'/Offender.php');
    unlink($directory.'/offender.ts');
    rmdir($directory);
});

test('the scanner leaves type docblocks and tooling pragmas alone', function (): void {
    $scanner = app(CommentScanner::class);
    $directory = sys_get_temp_dir().'/comment-scanner-'.bin2hex(random_bytes(6));

    mkdir($directory);

    file_put_contents($directory.'/Allowed.php', <<<'PHP'
        <?php

        class Allowed
        {
            /** @use HasFactory<AllowedFactory> */
            use HasFactory;

            /**
             * @param  list<string>  $keys
             * @return array{id: string, name: string}
             */
            public function run(array $keys): array
            {
                return ['id' => $keys[0], 'name' => $keys[1]];
            }
        }
        PHP);

    file_put_contents($directory.'/allowed.ts', <<<'TS'
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        export const loose: any = 1;

        const url = 'https://example.test/path';
        TS);

    expect($scanner->scanPhp($directory))->toBe([])
        ->and($scanner->scanFrontend($directory))->toBe([]);

    unlink($directory.'/Allowed.php');
    unlink($directory.'/allowed.ts');
    rmdir($directory);
});
