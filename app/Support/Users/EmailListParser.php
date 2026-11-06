<?php

declare(strict_types=1);

namespace App\Support\Users;

use Illuminate\Support\Str;

class EmailListParser
{
    /**
     * @return list<string>
     */
    public function parse(string $input): array
    {
        $addresses = preg_split('/[,;\r\n]+/', $input) ?: [];

        $normalized = [];

        foreach ($addresses as $address) {
            $address = Str::lower(trim($address));

            if ($address !== '') {
                $normalized[] = $address;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param  list<string>  $addresses
     * @return list<string>
     */
    public function invalid(array $addresses): array
    {
        return array_values(array_filter(
            $addresses,
            static fn (string $address): bool => filter_var($address, FILTER_VALIDATE_EMAIL) === false,
        ));
    }
}
