<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Enums\Notifications\RuleActionType;
use App\Exceptions\Engine\InvalidFilterTreeException;
use App\Models\NotificationRule;
use App\Models\User;
use App\Support\Notifications\RuleMatcher;
use App\Support\Notifications\RuleSegmentGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SaveNotificationRuleAction
{
    public function __construct(
        private readonly RuleSegmentGuard $segmentGuard,
        private readonly RuleMatcher $ruleMatcher,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input, User $creator): NotificationRule
    {
        $validated = Validator::make($input, $this->rules($creator))->validate();

        $this->segmentGuard->assertVisible($creator, $validated['segment_id'] ?? null);
        $this->assertFilterReadable($validated['object_type_id'], $validated['filter_definition'] ?? null, $creator);

        return NotificationRule::query()->create([
            'object_type_id' => $validated['object_type_id'],
            'name' => $validated['name'],
            'trigger_type' => $validated['trigger_type'],
            'config' => $validated['config'] ?? null,
            'segment_id' => $validated['segment_id'] ?? null,
            'filter_definition' => $validated['filter_definition'] ?? null,
            'action' => $validated['action'] ?? [RuleActionType::Notify->value => true, RuleActionType::CreateReminder->value => null],
            'is_active' => $validated['is_active'] ?? true,
            'created_by_id' => $creator->getKey(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(NotificationRule $rule, array $input, User $editor): NotificationRule
    {
        $validated = Validator::make($input, $this->rules($editor, false))->validate();

        $this->segmentGuard->assertVisible($editor, $validated['segment_id'] ?? null);
        $this->assertFilterReadable($rule->object_type_id, $validated['filter_definition'] ?? null, $editor);

        $rule->fill(array_filter(
            [
                'name' => $validated['name'] ?? null,
                'trigger_type' => $validated['trigger_type'] ?? null,
                'config' => $validated['config'] ?? null,
                'segment_id' => $validated['segment_id'] ?? null,
                'filter_definition' => $validated['filter_definition'] ?? null,
                'action' => $validated['action'] ?? null,
                'is_active' => $validated['is_active'] ?? null,
            ],
            static fn (mixed $value): bool => $value !== null,
        ));

        $rule->save();

        return $rule;
    }

    /**
     * @throws AuthorizationException
     * @throws InvalidFilterTreeException
     */
    private function assertFilterReadable(string $objectTypeId, mixed $filterDefinition, User $user): void
    {
        if (!is_array($filterDefinition) || $filterDefinition === []) {
            return;
        }

        $probe = new NotificationRule;
        $probe->object_type_id = $objectTypeId;
        $probe->filter_definition = $filterDefinition;

        $this->ruleMatcher->scopeQuery($probe, $user);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(User $user, bool $isCreate = true): array
    {
        $tenantId = TenantContext::currentId((string) $user->tenant_id);
        $required = $isCreate ? 'required' : 'sometimes';

        return [
            'object_type_id' => [$isCreate ? 'required' : 'prohibited', 'string', Rule::exists('object_types', 'id')->where('tenant_id', $tenantId)],
            'name' => [$required, 'string', 'max:255'],
            'trigger_type' => [$required, Rule::in(['date_based', 'assignment', 'field_change', 'creation', ...array_keys(config('modules.notifications.trigger_fields', []))])],
            'config' => ['nullable', 'array'],
            'segment_id' => ['nullable', 'string', Rule::exists('segments', 'id')->where('tenant_id', $tenantId)],
            'filter_definition' => ['nullable', 'array'],
            'action' => ['nullable', 'array'],
            'is_active' => ['boolean'],
        ];
    }
}
