<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;
use Tests\Support\ModuleRoots;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    /** @var callable():list<string> */
    $this->offenders = function (): array {
        $directories = array_filter(
            [app_path(), ...ModuleRoots::directories('src')],
            is_dir(...),
        );
        $offenders = [];

        foreach (Finder::create()->files()->in($directories)->name('*.php') as $file) {
            $contents = (string) file_get_contents($file->getRealPath());

            if (preg_match('/ShouldQueue|Bus::batch|::dispatch\(|\bdispatch\(\s*new\s|dispatch_sync\(|Queue::push/', $contents, $match) === 1) {
                $offenders[] = "{$file->getRealPath()} → {$match[0]}";
            }
        }

        sort($offenders);

        return $offenders;
    };
});

it('never hands work to the laravel queue because every asynchronous path is a temporal workflow', function (): void {
    expect(($this->offenders)())->toBe([]);
});
