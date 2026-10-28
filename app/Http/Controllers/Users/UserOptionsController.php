<?php

declare(strict_types=1);

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Abstracts\Controller;
use App\Support\Users\TenantUserOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserOptionsController extends Controller
{
    public function __construct(private readonly TenantUserOptions $userOptions) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $actor = $this->actingUser($request);

        return response()->json([
            'options' => $this->userOptions->forUser($actor, trim((string) ($validated['q'] ?? ''))),
        ]);
    }
}
