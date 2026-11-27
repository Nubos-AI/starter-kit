<?php

declare(strict_types=1);

namespace App\Enums\Sso;

enum SsoLoginPurpose: string
{
    case Login = 'login';

    case ConnectionTest = 'connection_test';
}
