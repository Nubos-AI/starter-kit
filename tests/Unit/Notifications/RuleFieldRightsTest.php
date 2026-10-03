<?php

declare(strict_types=1);

use App\Actions\Notifications\DeleteNotificationRuleAction;
use App\Actions\Notifications\SaveNotificationRuleAction;
use App\Enums\Notifications\RuleTriggerType;
use App\Http\Controllers\Notifications\NotificationRulesController;
use App\Models\CustomRecord;
use App\Models\NotificationRule;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Engine\ObjectTypeCapabilityGuard;
use App\Support\Notifications\RuleMatcher;
use App\Support\Notifications\RuleSegmentGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticRuleSegmentSource;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\Support\WriteAttempt;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('rule-rights-tenant');
    $this->objectTypeId = ModelStub::ulid('rule-rights-type');

    $this->matcher = new class extends RuleMatcher
    {
        /**
         * @var list<?string>
         */
        public array $viewers = [];

        public function __construct() {}

        public function scopeQuery(NotificationRule $rule, ?User $viewer = null): Builder
        {
            $this->viewers[] = $viewer instanceof User ? (string) $viewer->getKey() : null;

            return CustomRecord::query()->whereRaw('1 = 0');
        }
    };

    $this->segmentGuard = new RuleSegmentGuard(new StaticRuleSegmentSource);

    $this->tree = [
        'combinator' => 'and',
        'conditions' => [['field' => 'salary', 'operator' => 'equals', 'value' => '100']],
    ];

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => $this->objectTypeId,
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
        'capabilities' => ['records'],
    ]);

    $this->controllerWith = fn (RuleMatcher $matcher): NotificationRulesController => new class(app(SaveNotificationRuleAction::class), app(DeleteNotificationRuleAction::class), $matcher, app(ObjectTypeCapabilityGuard::class), $this->segmentGuard, $this->objectType) extends NotificationRulesController
    {
        public function __construct(
            SaveNotificationRuleAction $save,
            DeleteNotificationRuleAction $delete,
            RuleMatcher $matcher,
            ObjectTypeCapabilityGuard $capabilities,
            RuleSegmentGuard $segmentGuard,
            private readonly ObjectType $boundObjectType,
        ) {
            parent::__construct($save, $delete, $matcher, $capabilities, $segmentGuard);
        }

        protected function resolveObjectType(string $id): ObjectType
        {
            return $this->boundObjectType;
        }
    };

    $this->previewRequest = function (User $user, array $payload = []): Request {
        $request = Request::create('/notification-rules/preview', 'POST', [
            'object_type_id' => $this->objectTypeId,
            ...$payload,
        ]);
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('runs a saved filter through the rule matcher on behalf of the acting user', function (): void {
    $editor = AccessContext::user($this->tenant, [], 'rule-rights-editor');

    $action = new class($this->segmentGuard, $this->matcher) extends SaveNotificationRuleAction
    {
        /**
         * @param  array<string, mixed>  $input
         */
        public function createProbe(string $objectTypeId, array $tree, User $user): void
        {
            $reflection = new ReflectionMethod(SaveNotificationRuleAction::class, 'assertFilterReadable');
            $reflection->invoke($this, $objectTypeId, $tree, $user);
        }
    };

    $action->createProbe($this->objectTypeId, $this->tree, $editor);

    expect($this->matcher->viewers)->toBe([(string) $editor->getKey()]);
});

it('never runs an empty filter through the rule matcher', function (): void {
    $editor = AccessContext::user($this->tenant, [], 'rule-rights-editor');

    $action = new class($this->segmentGuard, $this->matcher) extends SaveNotificationRuleAction
    {
        /**
         * @param  array<string, mixed>|null  $tree
         */
        public function createProbe(string $objectTypeId, ?array $tree, User $user): void
        {
            $reflection = new ReflectionMethod(SaveNotificationRuleAction::class, 'assertFilterReadable');
            $reflection->invoke($this, $objectTypeId, $tree, $user);
        }
    };

    $action->createProbe($this->objectTypeId, [], $editor);
    $action->createProbe($this->objectTypeId, null, $editor);

    expect($this->matcher->viewers)->toBe([]);
});

it('carries the filter and the object type of the rule into the probe it hands the matcher', function (): void {
    $editor = AccessContext::user($this->tenant, [], 'rule-rights-editor');

    $matcher = new class extends RuleMatcher
    {
        public ?NotificationRule $probe = null;

        public function __construct() {}

        public function scopeQuery(NotificationRule $rule, ?User $viewer = null): Builder
        {
            $this->probe = $rule;

            return CustomRecord::query()->whereRaw('1 = 0');
        }
    };

    $action = new class($this->segmentGuard, $matcher) extends SaveNotificationRuleAction
    {
        /**
         * @param  array<string, mixed>  $tree
         */
        public function createProbe(string $objectTypeId, array $tree, User $user): void
        {
            $reflection = new ReflectionMethod(SaveNotificationRuleAction::class, 'assertFilterReadable');
            $reflection->invoke($this, $objectTypeId, $tree, $user);
        }
    };

    $action->createProbe($this->objectTypeId, $this->tree, $editor);

    expect($matcher->probe?->object_type_id)->toBe($this->objectTypeId)
        ->and($matcher->probe?->filter_definition)->toBe($this->tree);
});

it('counts a preview through the rule matcher on behalf of the acting user', function (): void {
    $viewer = AccessContext::actAs(AccessContext::user($this->tenant, [], 'rule-rights-viewer'));
    AccessContext::grant('companies.rules.manage');

    $reached = WriteAttempt::reachedTheDatabase(fn (): JsonResponse => ($this->controllerWith)($this->matcher)
        ->preview(($this->previewRequest)($viewer, ['filter_definition' => $this->tree])));

    expect($reached)->toBeTrue()
        ->and($this->matcher->viewers)->toBe([(string) $viewer->getKey()]);
});

it('refuses a preview to a user without the rules manage permission', function (): void {
    $viewer = AccessContext::actAs(AccessContext::user($this->tenant, [], 'rule-rights-viewer'));
    AccessContext::grant();

    expect(fn (): JsonResponse => ($this->controllerWith)($this->matcher)->preview(($this->previewRequest)($viewer)))
        ->toThrow(AuthorizationException::class)
        ->and($this->matcher->viewers)->toBe([]);
});

it('checks the named segment before it counts anything', function (): void {
    $viewer = AccessContext::actAs(AccessContext::user($this->tenant, [], 'rule-rights-viewer'));
    AccessContext::grant('companies.rules.manage');

    expect(fn (): JsonResponse => ($this->controllerWith)($this->matcher)
        ->preview(($this->previewRequest)($viewer, ['segment_id' => ModelStub::ulid('unknown-segment')])))
        ->toThrow(ValidationException::class)
        ->and($this->matcher->viewers)->toBe([]);
});

it('knows the trigger types that fire on an event and the one that does not', function (): void {
    expect(RuleTriggerType::DateBased->isEventBased())->toBeFalse()
        ->and(RuleTriggerType::Creation->isEventBased())->toBeTrue()
        ->and(RuleTriggerType::Assignment->isEventBased())->toBeTrue()
        ->and(RuleTriggerType::FieldChange->isEventBased())->toBeTrue();
});
