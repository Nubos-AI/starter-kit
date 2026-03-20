<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\{{Model}};
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetCurrent{{Model}}
{
    public function handle(Request $request, Closure $next): Response
    {
        ${{model}} = $request->route('{{model}}');

        if (! ${{model}} instanceof {{Model}}) {
            ${{model}} = {{Model}}::query()->where('slug', ${{model}})->first();
        }

        if (! ${{model}}) {
            abort(404);
        }

        if (! $request->user()->belongsTo{{Model}}(${{model}})) {
            abort(403);
        }

        $request->attributes->set('current_{{model}}', ${{model}});

        if ($request->user()->current_{{model}}_id !== ${{model}}->id) {
            $request->user()->update(['current_{{model}}_id' => ${{model}}->id]);
        }

        return $next($request);
    }
}
