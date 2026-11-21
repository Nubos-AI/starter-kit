<?php

declare(strict_types=1);

use Composer\ClassMapGenerator\ClassMapGenerator;

test('every class declared under tests complies with the psr-4 mapping, so composer never skips one', function (): void {
    $generator = new ClassMapGenerator;
    $generator->scanPaths(dirname(__DIR__, 2), null, 'psr-4', 'Tests\\');

    expect($generator->getClassMap()->getPsrViolations())->toBe([]);
});
