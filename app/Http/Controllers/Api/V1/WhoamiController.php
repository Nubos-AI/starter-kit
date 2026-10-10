<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Abstracts\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhoamiController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        return new JsonResponse([
            'id' => $user->getKey(),
            'abilities' => $user->currentAccessToken()->abilities,
        ]);
    }
}
