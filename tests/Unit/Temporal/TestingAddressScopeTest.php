<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    /** @var callable():list<string> */
    $this->filesBindingTheAddressAtLoadTime = static function (): array {
        $offenders = [];

        foreach (Finder::create()->files()->in(base_path('tests'))->name('*.php') as $file) {
            $contents = (string) file_get_contents($file->getRealPath());

            if (preg_match('/^(?:putenv\(\W*TEMPORAL_ADDRESS|\$(?:_ENV|_SERVER)\[\W*TEMPORAL_ADDRESS)/m', $contents) === 1) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        sort($offenders);

        return $offenders;
    };
});

it('never binds the temporal testing address at test file load time', function (): void {
    expect(($this->filesBindingTheAddressAtLoadTime)())->toBe([]);
});
