<?php

declare(strict_types=1);

namespace App\Http\Controllers\Preferences;

use App\Actions\Preferences\UpdateUserPreferencesAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Support\Preferences\UserPreferenceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserPreferencesController extends Controller
{
    public function __construct(private readonly UserPreferenceResolver $resolver) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->resolver->document($this->actingUser($request)));
    }

    public function update(Request $request, UpdateUserPreferencesAction $action): JsonResponse
    {
        $user = $this->actingUser($request);

        $action->execute($user, $request->all());

        return response()->json($this->resolver->document($user));
    }
}
