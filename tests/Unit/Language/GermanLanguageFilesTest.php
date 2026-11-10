<?php

declare(strict_types=1);

beforeEach(function (): void {
    $this->projectRoot = dirname(__DIR__, 3);

    /** @var callable(array<array-key, mixed>, string):array<string, string> */
    $this->flatten = function (array $lines, string $prefix = ''): array {
        $flat = [];

        foreach ($lines as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $flat = [...$flat, ...($this->flatten)($value, $path)];

                continue;
            }

            $flat[$path] = (string) $value;
        }

        return $flat;
    };

    /** @var callable(string):array<string, string> */
    $this->frameworkLines = fn (string $file): array => ($this->flatten)(require $this->projectRoot
        .'/vendor/laravel/framework/src/Illuminate/Translation/lang/en/'.$file.'.php');

    /** @var callable(string):array<string, string> */
    $this->germanLines = fn (string $file): array => ($this->flatten)(json_decode(
        (string) file_get_contents($this->projectRoot.'/resources/lang/de/i18n.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    )['framework'][$file]);

    /** @var callable(string):list<string> */
    $this->phpSourceFiles = function (string $directory): array {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        $files = [];

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    };
});

test('every framework language file laravel ships is fully translated into german', function (string $file): void {
    $missing = array_diff(
        array_keys(($this->frameworkLines)($file)),
        array_keys(($this->germanLines)($file)),
    );

    expect(($this->germanLines)($file))->not->toBeEmpty($file.' has no german translation')
        ->and($missing)->toBe([], "untranslated {$file} lines: ".implode(', ', $missing));
})->with([
    'validation' => ['validation'],
    'auth' => ['auth'],
    'passwords' => ['passwords'],
    'pagination' => ['pagination'],
]);

test('no german language line still carries its english source text', function (string $file): void {
    $english = ($this->frameworkLines)($file);
    $german = ($this->germanLines)($file);

    foreach ($english as $key => $text) {
        if (str_starts_with($key, 'custom.') || str_starts_with($key, 'attributes.')) {
            continue;
        }

        expect($german[$key] ?? null)->not->toBe($text, $file.'.'.$key.' is still english');
    }
})->with(['validation', 'auth', 'passwords', 'pagination']);

test('every attribute name the german validation file maps reads as a label, not as a column', function (): void {
    $attributes = ($this->germanLines)('validation');

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

    foreach (($this->phpSourceFiles)($this->projectRoot.'/app') as $file) {
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];

        foreach ($lines as $number => $line) {
            if (!str_contains($line, 'Log::')) {
                continue;
            }

            $statement = implode(' ', array_slice($lines, $number, 3));

            if (preg_match('/[äöüÄÖÜß]|\b(muss|nicht|wurde|konnte|Datensatz|Fehler)\b/u', $statement) === 1) {
                $offenders[] = str_replace($this->projectRoot.'/', '', $file).':'.($number + 1);
            }
        }
    }

    expect($offenders)->toBe([], 'german copy in log statements: '.implode(', ', $offenders));
});
