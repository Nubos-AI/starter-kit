<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Abstracts\Controller;
use App\Models\ObjectType;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AppearanceController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Appearance', [
            'startPageOptions' => $this->startPageOptions($this->actingUser($request)),
        ]);
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function startPageOptions(User $user): array
    {
        $objectTypes = ObjectType::query()
            ->orderBy('name')
            ->get()
            ->filter(fn (ObjectType $objectType): bool => $user->hasPermission("{$objectType->slug}.view"))
            ->map(fn (ObjectType $objectType): array => [
                'value' => (string) $objectType->getKey(),
                'label' => $objectType->name,
            ])
            ->values()
            ->all();

        return $objectTypes;
    }
}
