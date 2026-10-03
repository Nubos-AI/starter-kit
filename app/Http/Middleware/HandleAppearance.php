<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Preferences\GlobalPreference;
use App\Enums\Preferences\PreferenceScope;
use App\Models\User;
use App\Support\Preferences\UserPreferenceResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class HandleAppearance
{
    public function __construct(private readonly UserPreferenceResolver $preferences) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        View::share('appearance', $this->appearance($request));

        return $next($request);
    }

    private function appearance(Request $request): string
    {
        $user = $request->user();

        if ($user instanceof User) {
            $stored = $this->preferences->stored($user);
            $appearance = $stored[PreferenceScope::Settings->value][GlobalPreference::Appearance->value] ?? null;

            if (is_string($appearance) && $appearance !== '') {
                return $appearance;
            }
        }

        return is_string($request->cookie('appearance')) ? $request->cookie('appearance') : 'system';
    }
}
