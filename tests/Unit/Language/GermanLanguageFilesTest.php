<?php

declare(strict_types=1);

function languageProjectRoot(): string
{
    return dirname(__DIR__, 3);
}

/**
 * @param  array<array-key, mixed>  $lines
 * @return array<string, string>
 */
function flattenLanguageLines(array $lines, string $prefix = ''): array
{
    $flat = [];

    foreach ($lines as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            $flat = [...$flat, ...flattenLanguageLines($value, $path)];

            continue;
        }

        $flat[$path] = (string) $value;
    }

    return $flat;
}

/**
 * @return array<string, string>
 */
function frameworkLanguageLines(string $file): array
{
    return flattenLanguageLines(require languageProjectRoot()
        .'/vendor/laravel/framework/src/Illuminate/Translation/lang/en/'.$file.'.php');
}

/**
 * @return array<string, string>
 */
function germanLanguageLines(string $file): array
{
    return flattenLanguageLines(json_decode(
        file_get_contents(languageProjectRoot().'/resources/lang/de/i18n.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    )['framework'][$file]);
}

/** @return list<string> */
function phpSourceFiles(string $directory): array
{
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    $files = [];

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

test('every framework language file laravel ships is translated into german', function (string $file): void {
    expect(germanLanguageLines($file))->not->toBeEmpty($file.' has no german translation');
})->with(['validation', 'auth', 'passwords', 'pagination']);

test('the german validation file covers every rule laravel can produce', function (): void {
    $missing = array_diff(
        array_keys(frameworkLanguageLines('validation')),
        array_keys(germanLanguageLines('validation')),
    );

    expect($missing)->toBe([], 'untranslated validation lines: '.implode(', ', $missing));
});

test('no german language line still carries its english source text', function (string $file): void {
    $english = frameworkLanguageLines($file);
    $german = germanLanguageLines($file);

    foreach ($english as $key => $text) {
        if (str_starts_with($key, 'custom.') || str_starts_with($key, 'attributes.')) {
            continue;
        }

        expect($german[$key] ?? null)->not->toBe($text, $file.'.'.$key.' is still english');
    }
})->with(['validation', 'auth', 'passwords', 'pagination']);

test('every attribute name the german validation file maps reads as a label, not as a column', function (): void {
    $attributes = germanLanguageLines('validation');

    foreach ($attributes as $key => $label) {
        if (!str_starts_with($key, 'attributes.')) {
            continue;
        }

        expect($label)->not->toBe(substr($key, strlen('attributes.')), $key.' still shows its column name')
            ->and($label)->not->toContain('_', $key.' still shows a technical key');
    }
});

test('log messages stay english because no user ever reads them', function (): void {
    $offenders = [];

    foreach (phpSourceFiles(languageProjectRoot().'/app') as $file) {
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];

        foreach ($lines as $number => $line) {
            if (!str_contains($line, 'Log::')) {
                continue;
            }

            $statement = implode(' ', array_slice($lines, $number, 3));

            if (preg_match('/[äöüÄÖÜß]|\b(muss|nicht|wurde|konnte|Datensatz|Fehler)\b/u', $statement) === 1) {
                $offenders[] = str_replace(languageProjectRoot().'/', '', $file).':'.($number + 1);
            }
        }
    }

    expect($offenders)->toBe([], 'german copy in log statements: '.implode(', ', $offenders));
});
