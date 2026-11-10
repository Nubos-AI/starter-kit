<?php

declare(strict_types=1);

namespace App\Traits\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

trait RespondsWithValidationErrors
{
    private function validationResponse(ValidationException $exception): JsonResponse
    {
        return new JsonResponse(
            ['message' => $exception->getMessage(), 'errors' => $exception->errors()],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
