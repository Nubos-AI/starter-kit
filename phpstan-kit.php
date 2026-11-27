<?php

declare(strict_types=1);

$kitSupport = __DIR__.'/tests/Unit/Kit/Support';

return is_dir($kitSupport) ? ['parameters' => ['paths' => [$kitSupport]]] : [];
