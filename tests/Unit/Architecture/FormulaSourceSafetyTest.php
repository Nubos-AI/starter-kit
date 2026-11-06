<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    /** @var callable():list<string> */
    $this->offenders = function (): array {
        $offenders = [];
        $files = Finder::create()
            ->files()
            ->in([app_path('Support/Formulas'), app_path('Handlers/Formulas')])
            ->name('*.php');

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file->getRealPath());

            if (preg_match('/\b(?:eval|create_function|assert)\s*\(|PhpOffice/', $contents, $match) === 1) {
                $offenders[] = "{$file->getRelativePathname()} → {$match[0]}";
            }
        }

        sort($offenders);

        return $offenders;
    };
});

it('never hands a user formula to dynamic php code execution or a spreadsheet engine', function (): void {
    expect(($this->offenders)())->toBe([]);
});
