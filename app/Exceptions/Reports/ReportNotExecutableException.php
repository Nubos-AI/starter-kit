<?php

declare(strict_types=1);

namespace App\Exceptions\Reports;

use App\Enums\Reports\ReportNotExecutableReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ReportNotExecutableException extends RuntimeException
{
    public function __construct(
        public readonly ReportNotExecutableReason $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct($reason->message(), 0, $previous);
    }

    public function render(Request $request): JsonResponse
    {
        return new JsonResponse(
            ['message' => $this->getMessage(), 'reason' => $this->reason->value],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
