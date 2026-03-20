<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectToCurrent{{Model}}
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        ${{model}} = $user->current{{Model}} ?? $user->{{models}}()->first();

        if (! ${{model}}) {
            return $next($request);
        }

        $path = $request->path();

        return redirect("/{{models}}/{${{model}}->slug}/{$path}");
    }
}
