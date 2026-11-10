<?php

declare(strict_types=1);

namespace App\Support\Users;

use Illuminate\Support\Str;

class ServiceAccountEmail
{
    public static string $domain = 'service.invalid';

    public function generate(): string
    {
        return 'service-'.Str::lower((string) Str::ulid()).'-'.Str::lower(Str::random(16)).'@'.self::$domain;
    }

    public function isReserved(string $email): bool
    {
        $domain = Str::of($email)->trim()->lower()->afterLast('@')->toString();

        return $this->isReservedDomain($domain);
    }

    public function isReservedDomain(string $domain): bool
    {
        $domain = Str::of($domain)->trim()->lower()->ltrim('@')->toString();

        return $domain === self::$domain || Str::endsWith($domain, '.'.self::$domain);
    }
}
