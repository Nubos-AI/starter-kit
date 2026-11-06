<?php

declare(strict_types=1);

use App\Actions\Notifications\DeleteNotificationRuleAction;
use App\Actions\Notifications\SaveNotificationRuleAction;
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
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\Doubles\StaticRuleSegmentSource;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
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

it('runs a saved filter through the rule matcher on behalf of the editing user and carries filter and object type into the probe', function (): void {
    $editor = AccessContext::user($this->tenant, [], 'rule-rights-editor');

    $matcher = new class extends RuleMatcher
    {
        public ?NotificationRule $probe = null;

        /**
         * @var list<?string>
         */
        public array $viewers = [];

        public function __construct() {}

        public function scopeQuery(NotificationRule $rule, ?User $viewer = null): Builder
        {
            $this->probe = $rule;
            $this->viewers[] = $viewer instanceof User ? (string) $viewer->getKey() : null;

            return CustomRecord::query()->whereRaw('1 = 0');
        }
    };

    $rule = ModelStub::make(NotificationRule::class, [
        'id' => ModelStub::ulid('rule-rights-rule'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
    ]);

    StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);

    try {
        (new SaveNotificationRuleAction($this->segmentGuard, $matcher))->update($rule, ['filter_definition' => $this->tree], $editor);
    } finally {
        StaticQueryConnection::uninstall();
    }

    expect($matcher->viewers)->toBe([(string) $editor->getKey()])
        ->and($matcher->probe?->object_type_id)->toBe($this->objectTypeId)
        ->and($matcher->probe?->filter_definition)->toBe($this->tree);
});

it('never runs an empty filter through the rule matcher', function (?array $tree): void {
    $editor = AccessContext::user($this->tenant, [], 'rule-rights-editor');

    $rule = ModelStub::make(NotificationRule::class, [
        'id' => ModelStub::ulid('rule-rights-rule'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
        'name' => 'Regel',
    ]);

    StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);

    try {
        (new SaveNotificationRuleAction($this->segmentGuard, $this->matcher))->update($rule, ['filter_definition' => $tree, 'name' => 'Regel neu'], $editor);
    } finally {
        StaticQueryConnection::uninstall();
    }

    expect($this->matcher->viewers)->toBe([]);
})->with([
    'an empty tree' => [[]],
    'no tree at all' => [null],
]);

it('counts a preview through the rule matcher on behalf of the acting user', function (): void {
    $viewer = AccessContext::actAs(AccessContext::user($this->tenant, [], 'rule-rights-viewer'));
    AccessContext::grant('companies.rules.manage');

    $attempt = QueryShape::attemptedBy(fn (): JsonResponse => ($this->controllerWith)($this->matcher)
        ->preview(($this->previewRequest)($viewer, ['filter_definition' => $this->tree])));

    expect($attempt?->sql)->toStartWith('select count(*)')
        ->and($attempt?->targets('custom_records'))->toBeTrue()
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
