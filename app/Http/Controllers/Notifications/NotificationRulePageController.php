<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\FieldDefinitionResource;
use App\Models\NotificationRule;
use App\Models\ObjectType;
use Inertia\Inertia;
use Inertia\Response;

class NotificationRulePageController extends Controller
{
    public function index(ObjectType $objectType): Response
    {
        $objectType->load('fieldDefinitions');

        return Inertia::render('settings/NotificationRules', [
            'objectType' => [
                'id' => $objectType->id,
                'key' => $objectType->key,
                'slug' => $objectType->slug,
                'name' => $objectType->name,
            ],
            'fields' => FieldDefinitionResource::collection(
                $objectType->fieldDefinitions->sortBy('id')->values(),
            )->resolve(),
            'rules' => $this->rules($objectType),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rules(ObjectType $objectType): array
    {
        return NotificationRule::query()
            ->where('object_type_id', $objectType->getKey())
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (NotificationRule $rule): array => [
                'id' => $rule->getKey(),
                'objectTypeId' => $rule->object_type_id,
                'name' => $rule->name,
                'triggerType' => $rule->trigger_type->value,
                'trigger_type' => $rule->trigger_type->value,
                'config' => $rule->config,
                'segmentId' => $rule->segment_id,
                'filterDefinition' => $rule->filter_definition,
                'action' => $rule->action,
                'isActive' => $rule->is_active,
                'is_active' => $rule->is_active,
            ])
            ->all();
    }
}
