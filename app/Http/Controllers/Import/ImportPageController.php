<?php

declare(strict_types=1);

namespace App\Http\Controllers\Import;

use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\FieldDefinitionResource;
use App\Models\ImportJob;
use App\Models\ObjectType;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ImportPageController extends Controller
{
    public function wizard(Request $request, ObjectType $objectType): Response
    {
        $objectType->load('fieldDefinitions');

        return Inertia::render('import/ImportWizard', [
            'objectType' => $objectType->slug,
            'fields' => $this->fieldDefinitions($objectType),
        ]);
    }

    public function history(Request $request, ObjectType $objectType): Response
    {
        $user = $this->actingUser($request);

        $jobs = ImportJob::query()
            ->where('object_type_id', $objectType->getKey())
            ->where('user_id', $user->getKey())
            ->latest()
            ->get();

        return Inertia::render('import/ImportHistory', [
            'objectType' => $objectType->slug,
            'jobs' => $jobs,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fieldDefinitions(ObjectType $objectType): array
    {
        return FieldDefinitionResource::collection(
            $objectType->fieldDefinitions->sortBy('id')->values(),
        )->resolve();
    }
}
