<?php

declare(strict_types=1);

namespace App\Http\Controllers\Records;

use App\Http\Controllers\Abstracts\Controller;
use App\Models\CustomRecord;
use App\Models\User;
use App\Support\Users\TenantUserOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RecordCollaboratorsController extends Controller
{
    public function __construct(private readonly TenantUserOptions $userOptions) {}

    public function candidates(Request $request, CustomRecord $record): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $candidates = $this->userOptions
            ->candidates($record->tenant_id, trim((string) ($validated['q'] ?? '')))
            ->filter(fn (User $candidate): bool => Gate::forUser($candidate)->allows('view', $record))
            ->values();

        return response()->json(['options' => $this->userOptions->present($candidates)]);
    }
}
