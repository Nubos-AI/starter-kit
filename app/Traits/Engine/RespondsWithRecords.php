<?php

declare(strict_types=1);

namespace App\Traits\Engine;

use App\Http\Resources\RecordResource;
use App\Models\CustomRecord;
use App\Traits\Http\RespondsWithValidationErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait RespondsWithRecords
{
    use RespondsWithValidationErrors;

    private function recordResponse(Request $request, CustomRecord $record, int $status = 200): JsonResponse
    {
        return new JsonResponse(
            ['data' => RecordResource::make($record)->resolve($request)],
            $status,
        );
    }

    private function freshRecord(CustomRecord $record): CustomRecord
    {
        return CustomRecord::query()->whereKey($record->getKey())->firstOrFail();
    }
}
