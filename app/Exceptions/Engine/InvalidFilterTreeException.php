<?php

declare(strict_types=1);

namespace App\Exceptions\Engine;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class InvalidFilterTreeException extends RuntimeException
{
    public function __construct(
        string $message = 'i18n.backend.exceptions.engine.invalid_filter_tree_exception.the_filter_could_not_be_applied_please_review_it',
    ) {
        parent::__construct(__($message));
    }

    public function render(Request $request): JsonResponse
    {
        return new JsonResponse(['message' => $this->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
