<?php

declare(strict_types=1);

use App\Exceptions\Engine\RecordNumberOverflowException;
use App\Models\ObjectType;
use App\Support\Engine\BusinessKeyRules;
use App\Support\Engine\RecordNumberFormatter;
use App\Support\Engine\RecordRouteResolver;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->formatter = app(RecordNumberFormatter::class);

    /** @var callable(?string, ?string):ObjectType */
    $this->objectType = fn (?string $prefix, ?string $format): ObjectType => ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('business-key-object-type'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
        'key' => 'companies',
        'business_key_prefix' => $prefix,
        'record_number_format' => $format,
    ]);

    /** @var callable(array<string, mixed>):ValidatorInstance */
    $this->validating = fn (array $input): ValidatorInstance => Validator::make(
        $input,
        array_map(
            static fn (array $rules): array => array_values(array_filter(
                $rules,
                static fn (mixed $rule): bool => !$rule instanceof Unique,
            )),
            (new BusinessKeyRules)->all(),
        ),
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('pads the sequence into the places the format offers and keeps the prefix in front', function (): void {
    expect($this->formatter->format(($this->objectType)('CO', '####'), 0))->toBe('CO-0000')
        ->and($this->formatter->format(($this->objectType)('CO', '####'), 1))->toBe('CO-0001')
        ->and($this->formatter->format(($this->objectType)('CO', '##########'), 42))->toBe('CO-0000000042');
});

it('writes the digits into the placeholders and leaves the literal characters alone', function (): void {
    expect($this->formatter->format(($this->objectType)('CO', 'A#B##'), 7))->toBe('CO-A0B07');
});

it('falls back to the ten place default when no format was chosen', function (): void {
    expect($this->formatter->format(($this->objectType)('CO', null), 5))->toBe('CO-0000000005')
        ->and($this->formatter->format(($this->objectType)('CO', ''), 5))->toBe('CO-0000000005')
        ->and(RecordNumberFormatter::$defaultFormat)->toBe('##########');
});

it('hands out no record number at all without a prefix', function (): void {
    expect($this->formatter->format(($this->objectType)(null, '####'), 1))->toBeNull()
        ->and($this->formatter->format(($this->objectType)('', '####'), 1))->toBeNull();
});

it('refuses a sequence that no longer fits into the format', function (): void {
    expect(fn (): ?string => $this->formatter->format(($this->objectType)('CO', '##'), 100))
        ->toThrow(RecordNumberOverflowException::class);
});

it('demands an uppercase prefix of at most four characters', function (string $prefix, bool $accepted): void {
    $validator = ($this->validating)(['business_key_prefix' => $prefix]);

    expect($validator->passes())->toBe($accepted);
})->with([
    'four uppercase characters' => ['ABCD', true],
    'digits are allowed' => ['C1', true],
    'five characters' => ['ABCDE', false],
    'lowercase' => ['co', false],
    'a separator' => ['C-O', false],
    'empty' => ['', false],
]);

it('demands the prefix at all when an object type is created', function (): void {
    expect(fn (): array => ($this->validating)([])->validate())->toThrow(ValidationException::class);
});

it('leaves the prefix optional while an existing object type is edited', function (): void {
    $rules = (new BusinessKeyRules)->all(($this->objectType)('CO', '####'));

    expect($rules['business_key_prefix'][0])->toBe('sometimes');
});

it('demands a format with at least one hash, ten places at most and nothing but letters digits and hashes', function (string $format, bool $accepted): void {
    $validator = ($this->validating)(['business_key_prefix' => 'CO', 'record_number_format' => $format]);

    expect($validator->passes())->toBe($accepted);
})->with([
    'ten places' => ['##########', true],
    'a literal in between' => ['A#B##', true],
    'eleven places' => ['###########', false],
    'no placeholder at all' => ['ABCD', false],
    'a separator' => ['##-##', false],
]);

it('looks a record up by its key and by its business number but never outside the tenant', function (): void {
    $resolver = app(RecordRouteResolver::class);
    $ulid = ModelStub::ulid('business-key-record');

    $byKey = QueryShape::attemptedBy(fn (): mixed => $resolver->resolve($ulid));
    $byNumber = QueryShape::attemptedBy(fn (): mixed => $resolver->resolve('CO-0000000001'));

    expect($byKey)->not->toBeNull()
        ->and($byKey->isKeyedTo('custom_records', $ulid))->toBeTrue()
        ->and($byKey->isScopedToTenant('custom_records', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($byNumber)->not->toBeNull()
        ->and($byNumber->sql)->toContain('"record_number" = ?')
        ->and($byNumber->hasBinding('CO-0000000001'))->toBeTrue()
        ->and($byNumber->isScopedToTenant('custom_records', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($byNumber->hidesSoftDeleted('custom_records'))->toBeTrue();
});

it('lets only a ulid or a business key shaped identifier into the record route', function (): void {
    expect(preg_match('/^(?:'.RecordRouteResolver::routePattern().')$/', 'CO-0000000001'))->toBe(1)
        ->and(preg_match('/^(?:'.RecordRouteResolver::routePattern().')$/', ModelStub::ulid('probe')))->toBe(1)
        ->and(preg_match('/^(?:'.RecordRouteResolver::routePattern().')$/', "CO-1' OR 1=1"))->toBe(0)
        ->and(preg_match('/^(?:'.RecordRouteResolver::routePattern().')$/', 'co-1'))->toBe(0);
});
