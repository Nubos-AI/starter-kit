<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\Tenancy\DnsLookupFailedException;
use App\Support\Tenancy\DnsLookup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CheckWildcardDns extends Command
{
    protected $signature = 'tenancy:check-dns';

    protected $description = 'Prüft, ob der Wildcard-DNS-Eintrag für die Mandanten-Subdomains auf die Plattform zeigt';

    public function handle(DnsLookup $dns): int
    {
        $base = Str::lower((string) config('tenancy.welcome.base_domain'));
        $probe = 'nubos-dns-probe-'.Str::lower(Str::random(12)).".{$base}";

        try {
            $platform = $dns->addressesOf($base);
            $wildcard = $platform === [] ? [] : $dns->addressesOf($probe);
        } catch (DnsLookupFailedException) {
            $this->error(__('i18n.backend.actions.tenancy.verify_tenant_domain_action.dns_unavailable'));

            return self::FAILURE;
        }

        $isPointing = $wildcard !== [] && array_diff($wildcard, $platform) === [];

        Log::info('Wildcard DNS checked.', ['base_domain' => $base, 'is_pointing' => $isPointing]);

        if ($platform === []) {
            $this->error("Die Basis-Domain {$base} zeigt auf keine Adresse.");

            return self::FAILURE;
        }

        $platformList = implode(', ', $platform);

        if ($wildcard === []) {
            $this->error("Für *.{$base} fehlt der Wildcard-Eintrag. Legen Sie *.{$base} als A- bzw. AAAA-Eintrag auf {$platformList} an.");

            return self::FAILURE;
        }

        $wildcardList = implode(', ', $wildcard);

        if (!$isPointing) {
            $this->error("Der Wildcard-Eintrag *.{$base} zeigt auf {$wildcardList}, die Plattform liegt auf {$platformList}.");

            return self::FAILURE;
        }

        $this->info("Der Wildcard-Eintrag *.{$base} zeigt auf {$wildcardList}.");

        return self::SUCCESS;
    }
}
