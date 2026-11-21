<?php

declare(strict_types=1);

namespace App\Enums\Sso;

enum JwsAlgorithm: string
{
    case Rs256 = 'RS256';

    case Es256 = 'ES256';

    public function keyType(): string
    {
        return match ($this) {
            self::Rs256 => 'RSA',
            self::Es256 => 'EC',
        };
    }
}
