<?php

declare(strict_types=1);

use App\Models\Segment;
use App\Support\Notifications\RuleSegmentGuard;
use App\Support\Notifications\RuleSegmentSource;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticRuleSegmentSource;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('rule-segment-tenant');

    $this->segment = ModelStub::make(Segment::class, [
        'id' => ModelStub::ulid('rule-segment'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Offene Fälle',
    ]);

    $this->allowViewFor = static function (string ...$userIds): void {
        $allowed = array_values($userIds);

        Gate::before(static function (?Authenticatable $user, string $ability) use ($allowed): ?bool {
            if ($ability !== 'view') {
                return null;
            }

            return in_array((string) $user?->getAuthIdentifier(), $allowed, true);
        });
    };

    $this->guardWith = static fn (RuleSegmentSource $source): RuleSegmentGuard => new RuleSegmentGuard($source);

    $this->sourceKnowing = fn (): StaticRuleSegmentSource => new StaticRuleSegmentSource([
        (string) $this->segment->getKey() => $this->segment,
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('accepts a segment the acting user may see', function (): void {
    $user = AccessContext::user($this->tenant, [], 'rule-segment-viewer');
    ($this->allowViewFor)((string) $user->getKey());

    $source = ($this->sourceKnowing)();

    ($this->guardWith)($source)->assertVisible($user, (string) $this->segment->getKey());

    expect($source->askedFor)->toBe([(string) $this->segment->getKey()]);
});

it('refuses a segment the acting user may not see', function (): void {
    $user = AccessContext::user($this->tenant, [], 'rule-segment-blind');
    ($this->allowViewFor)();

    try {
        ($this->guardWith)(($this->sourceKnowing)())->assertVisible($user, (string) $this->segment->getKey());
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['segment_id']);

        return;
    }

    $this->fail('a rule was bound to a segment the user may not see');
});

it('refuses a segment that does not exist for the acting user', function (): void {
    $user = AccessContext::user($this->tenant, [], 'rule-segment-viewer');
    ($this->allowViewFor)((string) $user->getKey());

    expect(fn () => ($this->guardWith)(new StaticRuleSegmentSource)->assertVisible($user, ModelStub::ulid('unknown-segment')))
        ->toThrow(ValidationException::class);
});

it('refuses a segment reference that is no identifier at all', function (): void {
    $user = AccessContext::user($this->tenant, [], 'rule-segment-viewer');
    ($this->allowViewFor)((string) $user->getKey());

    $source = ($this->sourceKnowing)();

    expect(fn () => ($this->guardWith)($source)->assertVisible($user, ['nested']))
        ->toThrow(ValidationException::class)
        ->and($source->askedFor)->toBe([]);
});

it('asks for no segment at all when the rule names none', function (): void {
    $user = AccessContext::user($this->tenant, [], 'rule-segment-viewer');
    $source = ($this->sourceKnowing)();

    ($this->guardWith)($source)->assertVisible($user, null);

    expect($source->askedFor)->toBe([]);
});

it('looks a segment up inside the bound tenant only', function (): void {
    $segmentId = ModelStub::ulid('rule-segment');

    $attempt = QueryShape::attemptedBy(fn (): ?Segment => (new RuleSegmentSource)->find($segmentId));

    expect($attempt)->not->toBeNull()
        ->and($attempt?->targets('segments'))->toBeTrue()
        ->and($attempt?->isKeyedTo('segments', $segmentId))->toBeTrue()
        ->and($attempt?->isScopedToTenant('segments', (string) $this->tenant->getKey()))->toBeTrue();
});
