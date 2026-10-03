<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supported = config('app.supported_locales', ['de', 'en']);
        $default = config('app.default_locale', 'de');
        $preferred = in_array($default, $supported, true)
            ? array_values(array_unique([$default, ...$supported]))
            : $supported;
        $sessionLocale = $request->hasSession() ? $request->session()->get('locale') : null;
        $locale = is_string($sessionLocale) && in_array($sessionLocale, $supported, true)
            ? $sessionLocale
            : ($request->getPreferredLanguage($preferred) ?? $default);

        App::setLocale($locale);

        return $next($request);
    }
}
