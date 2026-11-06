<?php

declare(strict_types=1);

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->files = new Filesystem;
    $this->fixture = sys_get_temp_dir().'/nubos-package-tests-'.bin2hex(random_bytes(8));
    $this->files->mkdir([$this->fixture.'/vendor/bin', $this->fixture.'/vendor/nubos/alpha/tests', $this->fixture.'/vendor/nubos/beta/tests']);
    touch($this->fixture.'/vendor/nubos/alpha/phpunit.xml');
    touch($this->fixture.'/vendor/nubos/beta/phpunit.xml');
    file_put_contents($this->fixture.'/vendor/bin/pest', <<<'SCRIPT'
#!/bin/sh
echo "$1" >> suites-run
case "$1" in *alpha*) exit 1 ;; esac
exit 0
SCRIPT);
    chmod($this->fixture.'/vendor/bin/pest', 0755);

    $script = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/composer.json'), true, flags: JSON_THROW_ON_ERROR)['scripts']['test:packages'];
    $this->runScript = function () use ($script): Process {
        $process = Process::fromShellCommandline((string) end($script), $this->fixture);
        $process->run();

        return $process;
    };
});

afterEach(function (): void {
    $this->files->remove($this->fixture);
});

it('runs every package suite even after one of them failed and still reports the failure', function (): void {
    $process = ($this->runScript)();

    expect(file($this->fixture.'/suites-run', FILE_IGNORE_NEW_LINES))->toBe([
        '--configuration=vendor/nubos/alpha/phpunit.xml',
        '--configuration=vendor/nubos/beta/phpunit.xml',
    ])->and($process->isSuccessful())->toBeFalse();
});
