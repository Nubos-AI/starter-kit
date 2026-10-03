<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\Resources\RecordResource;
use App\Models\CustomRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class StaleRecordException extends RuntimeException
{
    private ?CustomRecord $record = null;

    public function __construct(
        string $message = 'i18n.backend.exceptions.stale_record_exception.someone_else_has_changed_this_record_please_reload_it',
    ) {
        parent::__construct(__($message));
    }

    public function withRecord(CustomRecord $record): self
    {
        $this->record = $record;

        return $this;
    }

    public function currentVersion(): ?int
    {
        return $this->record?->version;
    }

    public function render(Request $request): JsonResponse
    {
        $body = ['message' => $this->getMessage()];

        if ($this->record instanceof CustomRecord) {
            $body['data'] = RecordResource::make($this->record)->resolve($request);
        }

        return new JsonResponse($body, Response::HTTP_CONFLICT);
    }
}
