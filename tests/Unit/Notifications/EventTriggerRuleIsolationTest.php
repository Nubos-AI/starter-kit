<?php

declare(strict_types=1);

use App\Activities\Notifications\EvaluateNotificationRulesActivity;
use App\DTOs\Engine\RecordChange;
use App\DTOs\Engine\RecordChangeBatch;
use App\Enums\Notifications\RuleTriggerType;
use App\Exceptions\Notifications\RuleDispatchFailedException;
use App\Models\CustomRecord;
use App\Models\NotificationRule;
use App\Support\Notifications\EventTriggerRuleEvaluator;
use App\Support\Notifications\RuleActionRunner;
use App\Support\Notifications\RuleMatcher;
use Illuminate\Support\Facades\Log;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenantId = ModelStub::ulid('event-rule-tenant');
    $this->objectTypeId = ModelStub::ulid('event-rule-type');
    $this->recordId = ModelStub::ulid('event-rule-record');
    $this->brokenRuleId = ModelStub::ulid('event-rule-broken');
    $this->healthyRuleId = ModelStub::ulid('event-rule-healthy');

    $this->ruleRow = fn (string $id): array => [
        'id' => $id,
        'tenant_id' => $this->tenantId,
        'object_type_id' => $this->objectTypeId,
        'name' => 'Rule',
        'trigger_type' => RuleTriggerType::eventTypes()[0]->value,
        'is_active' => true,
        'segment_id' => null,
        'filter_definition' => null,
    ];

    $this->change = fn (string $recordSeed): RecordChange => RecordChange::fromArray([
        'tenant_id' => $this->tenantId,
        'object_type_id' => $this->objectTypeId,
        'record_id' => ModelStub::ulid($recordSeed),
        'sequence' => 1,
        'version' => 2,
    ]);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('still runs the other matching rules of a change when one rule cannot reach its recipients, and reports the failure afterwards', function (): void {
    StaticQueryConnection::install(fn (string $sql): array => match (true) {
        str_contains($sql, 'from "tenants"') => [['id' => $this->tenantId, 'name' => 'Nubos', 'slug' => 'nubos']],
        str_contains($sql, 'from "custom_records"') => [['id' => $this->recordId, 'tenant_id' => $this->tenantId, 'object_type_id' => $this->objectTypeId]],
        str_contains($sql, 'from "notification_rules"') => [($this->ruleRow)($this->brokenRuleId), ($this->ruleRow)($this->healthyRuleId)],
        default => [],
    });
    Log::spy();
    $matcher = Mockery::mock(RuleMatcher::class);
    $matcher->shouldReceive('matchesEvent')->andReturnTrue();
    $failure = new RuleDispatchFailedException('unreachable');
    $runner = Mockery::mock(RuleActionRunner::class);
    $ran = [];
    $runner->shouldReceive('run')->twice()->andReturnUsing(function (NotificationRule $rule, CustomRecord $record, string $stage) use (&$ran, $failure): void {
        $ran[] = [$rule->getKey(), $stage];

        if ($rule->getKey() === $this->brokenRuleId) {
            throw $failure;
        }
    });

    expect(fn () => (new EventTriggerRuleEvaluator($matcher, $runner))->evaluate($this->tenantId, $this->objectTypeId, $this->recordId, 2, ['name']))
        ->toThrow(RuleDispatchFailedException::class, 'unreachable')
        ->and($ran)->toBe([[$this->brokenRuleId, 'v2'], [$this->healthyRuleId, 'v2']]);

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $context['rule_id'] === $this->brokenRuleId
        && $context['record_id'] === $this->recordId
        && $context['tenant_id'] === $this->tenantId
        && $context['exception'] === $failure);
});

it('still evaluates the other changes of a batch when one change fails, and reports the failure afterwards', function (): void {
    Log::spy();
    $failure = new RuntimeException('rule evaluation broke');
    $evaluator = Mockery::mock(EventTriggerRuleEvaluator::class);
    $evaluated = [];
    $evaluator->shouldReceive('evaluate')->twice()->andReturnUsing(function (string $tenantId, string $objectTypeId, string $recordId) use (&$evaluated, $failure): void {
        $evaluated[] = $recordId;

        if ($recordId === ModelStub::ulid('event-rule-broken-record')) {
            throw $failure;
        }
    });
    $batch = new RecordChangeBatch([($this->change)('event-rule-broken-record'), ($this->change)('event-rule-healthy-record')]);

    expect(fn () => (new EvaluateNotificationRulesActivity($evaluator))->evaluateRules($batch))
        ->toThrow(RuntimeException::class, 'rule evaluation broke')
        ->and($evaluated)->toBe([ModelStub::ulid('event-rule-broken-record'), ModelStub::ulid('event-rule-healthy-record')]);

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $context['record_id'] === ModelStub::ulid('event-rule-broken-record')
        && $context['tenant_id'] === $this->tenantId
        && $context['exception'] === $failure);
});
