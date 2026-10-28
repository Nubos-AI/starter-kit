<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notifications;

use App\Actions\Notifications\DeleteNotificationRuleAction;
use App\Actions\Notifications\SaveNotificationRuleAction;
use App\Enums\Authorization\ObjectTypeAbility;
use App\Enums\Engine\ObjectTypeCapability;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\NotificationRule;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Engine\ObjectTypeCapabilityGuard;
use App\Support\Notifications\RuleMatcher;
use App\Support\Notifications\RuleSegmentGuard;
use App\Traits\Http\RespondsWithValidationErrors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class NotificationRulesController extends Controller
{
    use RespondsWithValidationErrors;

    private int $previewCap = 10_000;

    public function __construct(
        private readonly SaveNotificationRuleAction $saveNotificationRuleAction,
        private readonly DeleteNotificationRuleAction $deleteNotificationRuleAction,
        private readonly RuleMatcher $ruleMatcher,
        private readonly ObjectTypeCapabilityGuard $capabilities,
        private readonly RuleSegmentGuard $segmentGuard,
    ) {}

    public function index(Request $request, ObjectType $objectType): JsonResponse
    {
        $user = $this->actingUser($request);
        $this->authorizeManage($user, $objectType);

        $rules = NotificationRule::query()
            ->where('object_type_id', $objectType->getKey())
            ->orderByDesc('created_at')
            ->get();

        return new JsonResponse(['data' => $rules->map(fn (NotificationRule $rule): array => $this->present($rule))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->actingUser($request);
        $type = $this->resolveObjectType((string) $request->input('object_type_id'));
        $this->authorizeManage($user, $type);

        try {
            $rule = $this->saveNotificationRuleAction->create($request->all(), $user);
        } catch (ValidationException $exception) {
            return $this->validationResponse($exception);
        }

        return new JsonResponse(['data' => $this->present($rule)], Response::HTTP_CREATED);
    }

    public function update(Request $request, NotificationRule $rule): JsonResponse
    {
        $user = $this->actingUser($request);
        $this->authorizeManage($user, $rule->objectType);

        try {
            $updated = $this->saveNotificationRuleAction->update($rule, $request->all(), $user);
        } catch (ValidationException $exception) {
            return $this->validationResponse($exception);
        }

        return new JsonResponse(['data' => $this->present($updated)]);
    }

    public function destroy(Request $request, NotificationRule $rule): JsonResponse
    {
        $user = $this->actingUser($request);
        $this->authorizeManage($user, $rule->objectType);

        $this->deleteNotificationRuleAction->execute($rule);

        return new JsonResponse(status: Response::HTTP_NO_CONTENT);
    }

    public function preview(Request $request): JsonResponse
    {
        $user = $this->actingUser($request);
        $type = $this->resolveObjectType((string) $request->input('object_type_id'));
        $this->authorizeManage($user, $type);

        $validated = $request->validate([
            'object_type_id' => ['required', 'string'],
            'segment_id' => ['nullable', 'string'],
            'filter_definition' => ['nullable', 'array'],
        ]);

        $this->segmentGuard->assertVisible($user, $validated['segment_id'] ?? null);

        $rule = new NotificationRule;
        $rule->object_type_id = $type->getKey();
        $rule->segment_id = $validated['segment_id'] ?? null;
        $rule->filter_definition = $validated['filter_definition'] ?? null;

        $count = $this->ruleMatcher->scopeQuery($rule, $user)->count();

        return new JsonResponse([
            'count' => $count,
            'approximate' => $count >= $this->previewCap,
        ]);
    }

    private function authorizeManage(User $user, ?ObjectType $objectType): void
    {
        if (!$objectType instanceof ObjectType
            || !$user->hasPermission("{$objectType->slug}.".ObjectTypeAbility::RulesManage->value)) {
            throw new AuthorizationException(__('i18n.backend.http.controllers.notifications.notification_rules_controller.you_may_not_manage_notification_rules_for_this_object'));
        }

        $this->capabilities->assertSupports($objectType, ObjectTypeCapability::Records);
    }

    protected function resolveObjectType(string $id): ObjectType
    {
        return ObjectType::query()->whereKey($id)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(NotificationRule $rule): array
    {
        return [
            'id' => $rule->getKey(),
            'objectTypeId' => $rule->object_type_id,
            'name' => $rule->name,
            'triggerType' => $rule->trigger_type->value,
            'config' => $rule->config,
            'segmentId' => $rule->segment_id,
            'filterDefinition' => $rule->filter_definition,
            'action' => $rule->action,
            'isActive' => $rule->is_active,
        ];
    }
}
