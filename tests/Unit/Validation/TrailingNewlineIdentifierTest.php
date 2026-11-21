<?php

declare(strict_types=1);

use App\Actions\Engine\CreateFieldDefinitionAction;
use App\Enums\CustomFields\FieldType;
use App\Support\Engine\BusinessKeyRules;
use App\Support\Engine\IndexRegistry;
use App\Support\Sso\JwkPublicKeyConverter;
use App\Support\Tenancy\WelcomeTenantResolver;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakePresenceVerifier;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    AccessContext::tenant('trailing-newline');

    $this->objectTypeId = ModelStub::ulid('trailing-newline-object-type');

    /** @var callable(Closure():mixed):array<string, list<string>> */
    $this->validationErrorsOf = function (Closure $callback): array {
        try {
            QueryShape::attemptedBy($callback);
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        return [];
    };

    /** @var callable(string):array<string, list<string>> */
    $this->fieldKeyErrorsOf = fn (string $key): array => ($this->validationErrorsOf)(
        fn () => app(CreateFieldDefinitionAction::class)->execute([
            'object_type_id' => $this->objectTypeId,
            'key' => $key,
            'field_type' => FieldType::TextShort->value,
        ]),
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses a field key that ends in a newline', function (): void {
    FakePresenceVerifier::holding(['object_types' => [$this->objectTypeId], 'field_definitions' => []]);

    expect(($this->fieldKeyErrorsOf)("industry\n"))->toHaveKey('key')
        ->and(($this->fieldKeyErrorsOf)('industry'))->not->toHaveKey('key');
});

it('refuses a business key prefix that ends in a newline', function (): void {
    FakePresenceVerifier::holding(['object_types' => []]);

    $rules = (new BusinessKeyRules)->all();

    expect(Validator::make(['business_key_prefix' => "FI\n"], $rules)->fails())->toBeTrue()
        ->and(Validator::make(['business_key_prefix' => 'FI'], $rules)->fails())->toBeFalse();
});

it('refuses a key that ends in a newline at the index key guard before it reaches the byte allowlist', function (): void {
    $registry = new IndexRegistry;

    expect($registry->safeKey('industry'))->toBe('industry')
        ->and(fn (): string => $registry->safeKey("industry\n"))->toThrow(InvalidArgumentException::class, 'is not a valid index identifier');
});

it('treats a host that ends in a newline as uncertifiable without looking it up', function (): void {
    $resolver = app(WelcomeTenantResolver::class);

    $result = null;
    $shape = QueryShape::attemptedBy(function () use ($resolver, &$result): void {
        $result = $resolver->isCertifiable("customer-domain.de\n");
    });

    expect($shape)->toBeNull()
        ->and($result)->toBeFalse();
});

it('refuses a base64url key component that ends in a newline', function (): void {
    $converter = app(JwkPublicKeyConverter::class);

    expect($converter->decodeBase64Url('AQAB'))->toBe("\x01\x00\x01")
        ->and($converter->decodeBase64Url("AQAB\n"))->toBeNull();
});
