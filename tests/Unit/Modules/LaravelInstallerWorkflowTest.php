<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

beforeEach(function (): void {
    $this->workflow = dirname(__DIR__, 3).'/.github/workflows/tests.yml';
});

test('the kit ships the test workflow the laravel installer rewrites for pest and the local php version', function (): void {
    expect($this->workflow)->toBeFile();

    $workflow = Yaml::parseFile($this->workflow);

    expect($workflow['jobs']['ci']['steps'])->toContain([
        'name' => 'Run CI checks',
        'run' => 'composer ci:check',
    ]);
});

test('the test workflow stays idle on the published starter kit mirror and runs in every application created from it', function (): void {
    $workflow = Yaml::parseFile($this->workflow);

    expect($workflow['jobs']['ci']['if'])->toBe("github.repository != 'Nubos-AI/starter-kit'");
});
