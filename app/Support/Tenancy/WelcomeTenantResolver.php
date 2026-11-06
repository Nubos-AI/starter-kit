<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\DTOs\Tenancy\SiteAddress;
use App\Enums\Tenancy\WelcomeAddressing;
use App\Models\Team;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WelcomeTenantResolver
{
    public static string $slugPattern = '[a-z0-9]+(?:-[a-z0-9]+)*';

    public static string $sitePathPattern = '[a-z0-9-]+(?:/[a-z0-9-]+)*';

    public static string $hostPattern = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    public function __construct(private readonly PublicSiteProviders $sites) {}

    /**
     * @throws ModelNotFoundException<Model>
     */
    public function resolve(Request $request): ?SiteAddress
    {
        $sitePath = $request->route('sitePath');
        $segments = is_string($sitePath) ? array_values(array_filter(explode('/', $sitePath))) : [];

        if ($this->addressing() === WelcomeAddressing::Path) {
            return $segments === [] ? null : $this->byPath($segments);
        }

        $address = $this->byHost(Str::lower($request->getHost()));

        if ($address === null && $segments !== []) {
            throw $this->unknown(Tenant::class);
        }

        return $address?->atPath(implode('/', $segments));
    }

    public function addressOf(Tenant $tenant, ?Team $team = null): string
    {
        if ($this->addressing() === WelcomeAddressing::Path) {
            $teamPath = $team === null ? '' : "/{$team->slug}";

            return rtrim((string) config('app.url'), '/')."/{$tenant->slug}{$teamPath}";
        }

        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        return "{$scheme}://{$this->subdomainOf($tenant, $team)}";
    }

    public function subdomainOf(Tenant $tenant, ?Team $team = null): string
    {
        $teamLabel = $team === null ? '' : "{$team->slug}.";

        return "{$teamLabel}{$tenant->slug}.{$this->baseDomain()}";
    }

    public function isPlatformHost(string $host): bool
    {
        $base = $this->baseDomain();

        return $host === $base || Str::endsWith($host, ".{$base}");
    }

    public function isCertifiable(string $host): bool
    {
        $host = Str::of($host)->lower()->rtrim('.')->toString();

        if (preg_match(self::$hostPattern, $host) !== 1) {
            return false;
        }

        if ($this->isPlatformRoot($host)) {
            return true;
        }

        if ($this->isPlatformHost($host)) {
            return $this->isLivingPlatformSubdomain($host);
        }

        return Tenant::query()
            ->where('domain', $host)
            ->whereNotNull('domain_verified_at')
            ->exists()
            || $this->sites->siteOfDomain($host) !== null;
    }

    /**
     * @param  list<string>  $segments
     *
     * @throws ModelNotFoundException<Model>
     */
    private function byPath(array $segments): SiteAddress
    {
        $tenant = $this->bySlug(array_shift($segments));
        $team = $segments === [] ? null : $this->topLevelTeamQuery($tenant, $segments[0])->first();

        if ($team instanceof Team) {
            array_shift($segments);
        }

        return new SiteAddress($tenant, $team, implode('/', $segments));
    }

    /**
     * @throws ModelNotFoundException<Model>
     */
    private function byHost(string $host): ?SiteAddress
    {
        if ($this->isPlatformRoot($host)) {
            return null;
        }

        if ($this->isPlatformHost($host)) {
            return $this->byPlatformSubdomain($host);
        }

        if (preg_match(self::$hostPattern, $host) !== 1) {
            return null;
        }

        $tenant = Tenant::query()->where('domain', $host)->first();

        return $tenant === null ? $this->sites->siteOfDomain($host) : new SiteAddress($tenant);
    }

    /**
     * @throws ModelNotFoundException<Model>
     */
    private function byPlatformSubdomain(string $host): SiteAddress
    {
        $labels = $this->labelsOf($host);

        if ($labels === null) {
            throw $this->unknown(Tenant::class);
        }

        [$tenantSlug, $teamSlug] = $labels;
        $tenant = $this->bySlug($tenantSlug);

        if ($teamSlug === null) {
            return new SiteAddress($tenant);
        }

        $team = $this->topLevelTeamQuery($tenant, $teamSlug)->first();

        if (!$team instanceof Team) {
            throw $this->unknown(Team::class);
        }

        return new SiteAddress($tenant, $team);
    }

    private function isLivingPlatformSubdomain(string $host): bool
    {
        $labels = $this->labelsOf($host);

        if ($labels === null) {
            return false;
        }

        [$tenantSlug, $teamSlug] = $labels;

        if ($teamSlug === null) {
            return Tenant::query()->where('slug', $tenantSlug)->exists();
        }

        return Team::withoutTenantScope()
            ->whereNull('parent_team_id')
            ->where('slug', $teamSlug)
            ->whereIn('tenant_id', Tenant::query()->select('id')->where('slug', $tenantSlug))
            ->exists();
    }

    /**
     * @return array{0: string, 1: string|null}|null
     */
    private function labelsOf(string $host): ?array
    {
        $labels = explode('.', Str::beforeLast($host, ".{$this->baseDomain()}"));

        if (count($labels) > 2) {
            return null;
        }

        foreach ($labels as $label) {
            if (preg_match('/^'.self::$slugPattern.'$/', $label) !== 1) {
                return null;
            }
        }

        return count($labels) === 2 ? [$labels[1], $labels[0]] : [$labels[0], null];
    }

    /**
     * @return Builder<Team>
     */
    private function topLevelTeamQuery(Tenant $tenant, string $slug): Builder
    {
        return Team::withoutTenantScope()
            ->where('tenant_id', $tenant->getKey())
            ->whereNull('parent_team_id')
            ->where('slug', $slug);
    }

    private function isPlatformRoot(string $host): bool
    {
        return $host === $this->baseDomain() || $host === Str::lower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    }

    /**
     * @throws ModelNotFoundException<Model>
     */
    private function bySlug(string $slug): Tenant
    {
        if (preg_match('/^'.self::$slugPattern.'$/', $slug) !== 1) {
            throw $this->unknown(Tenant::class);
        }

        return Tenant::query()->where('slug', $slug)->firstOrFail();
    }

    /**
     * @param  class-string<Model>  $model
     * @return ModelNotFoundException<Model>
     */
    private function unknown(string $model): ModelNotFoundException
    {
        return (new ModelNotFoundException)->setModel($model);
    }

    private function addressing(): WelcomeAddressing
    {
        return WelcomeAddressing::tryFrom((string) config('tenancy.welcome.addressing')) ?? WelcomeAddressing::Host;
    }

    private function baseDomain(): string
    {
        return Str::lower((string) config('tenancy.welcome.base_domain'));
    }
}
