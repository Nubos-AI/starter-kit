<?php

declare(strict_types=1);

use App\DTOs\Scim\ScimUserChanges;
use App\Enums\Scim\ScimErrorType;
use App\Enums\Scim\ScimPatchOperation;
use App\Exceptions\Scim\ScimException;
use App\Support\Scim\ScimFilterParser;
use App\Support\Scim\ScimPatchParser;
use App\Support\Scim\ScimUserMapper;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    app()->setLocale('de');

    $this->scimError = static function (Closure $call): ?array {
        try {
            $call();
        } catch (ScimException $exception) {
            return [$exception->status, $exception->scimType];
        }

        return null;
    };

    $this->patch = static fn (array $operations): array => [
        'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
        'Operations' => $operations,
    ];
});

it('reads an equality filter on an allowed attribute regardless of the case of attribute and operator', function (string $filter, string $attribute, string $value): void {
    $parsed = app(ScimFilterParser::class)->parse($filter, ['username', 'externalid', 'emails.value']);

    expect($parsed->attribute)->toBe($attribute)
        ->and($parsed->value)->toBe($value);
})->with([
    ['userName eq "ada@acme.de"', 'username', 'ada@acme.de'],
    ['USERNAME EQ "Ada@Acme.de"', 'username', 'Ada@Acme.de'],
    ['externalId eq "00u1 \"quoted\" id"', 'externalid', '00u1 "quoted" id'],
    ['emails.value eq "ada@acme.de"', 'emails.value', 'ada@acme.de'],
    ['  userName   eq   "ada@acme.de"  ', 'username', 'ada@acme.de'],
]);

it('treats a missing or empty filter as no filter', function (?string $filter): void {
    expect(app(ScimFilterParser::class)->parse($filter, ['username']))->toBeNull();
})->with([null, '', '   ']);

it('refuses every other attribute, operator or expression with 400 invalidFilter', function (string $filter): void {
    expect(($this->scimError)(fn () => app(ScimFilterParser::class)->parse($filter, ['username', 'externalid'])))->toBe([400, ScimErrorType::InvalidFilter]);
})->with([
    'unknown attribute' => ['password eq "secret"'],
    'other operator' => ['userName co "acme"'],
    'presence' => ['userName pr'],
    'combined' => ['userName eq "a" or userName eq "b"'],
    'unquoted value' => ['userName eq ada'],
    'trailing garbage' => ['userName eq "a" garbage'],
    'complex attribute filter' => ['emails[type eq "work"]'],
]);

it('reads the patch operations of a patch request', function (): void {
    $operations = app(ScimPatchParser::class)->parse(($this->patch)([
        ['op' => 'Replace', 'path' => 'active', 'value' => 'False'],
        ['op' => 'add', 'value' => ['name' => ['givenName' => 'Ada']]],
        ['op' => 'remove', 'path' => 'externalId'],
    ]));

    expect($operations)->toHaveCount(3)
        ->and($operations[0]->operation)->toBe(ScimPatchOperation::Replace)
        ->and($operations[0]->path)->toBe('active')
        ->and($operations[1]->operation)->toBe(ScimPatchOperation::Add)
        ->and($operations[1]->path)->toBeNull()
        ->and($operations[2]->operation)->toBe(ScimPatchOperation::Remove);
});

it('refuses a patch request without the patch schema, without operations or with an unknown operation', function (array $body): void {
    expect(($this->scimError)(fn () => app(ScimPatchParser::class)->parse($body)))->toBe([400, ScimErrorType::InvalidSyntax]);
})->with([
    'no schema' => [['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]]],
    'no operations' => [['schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'], 'Operations' => []]],
    'unknown operation' => [['schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'], 'Operations' => [['op' => 'move', 'path' => 'active']]]],
    'remove without path' => [['schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'], 'Operations' => [['op' => 'remove']]]],
]);

it('turns the user patch operations into the changes they describe', function (array $operations, array $expected): void {
    $changes = app(ScimUserMapper::class)->fromPatch(app(ScimPatchParser::class)->parse(($this->patch)($operations)));

    expect($changes)->toBeInstanceOf(ScimUserChanges::class)
        ->and($changes->values)->toBe($expected);
})->with([
    'deactivate as string' => [[['op' => 'Replace', 'path' => 'active', 'value' => 'False']], ['is_active' => false]],
    'activate as boolean' => [[['op' => 'replace', 'path' => 'active', 'value' => true]], ['is_active' => true]],
    'rename by path' => [[['op' => 'replace', 'path' => 'name.familyName', 'value' => 'Byron'], ['op' => 'add', 'path' => 'name.givenName', 'value' => 'Augusta']], ['last_name' => 'Byron', 'first_name' => 'Augusta']],
    'value object without path' => [[['op' => 'replace', 'value' => ['active' => false, 'userName' => 'Ada@ACME.de', 'externalId' => 'ext-9']]], ['is_active' => false, 'email' => 'ada@acme.de', 'external_id' => 'ext-9']],
    'work email by filter path' => [[['op' => 'replace', 'path' => 'emails[type eq "work"].value', 'value' => 'ada@acme.de']], ['email' => 'ada@acme.de']],
    'remove external id and family name' => [[['op' => 'remove', 'path' => 'externalId'], ['op' => 'remove', 'path' => 'name.familyName']], ['external_id' => null, 'last_name' => null]],
    'unsupported attribute is ignored' => [[['op' => 'replace', 'path' => 'title', 'value' => 'CTO']], []],
]);

it('refuses a patch that removes the active flag or the user name or sets active to no boolean', function (array $operation): void {
    expect(($this->scimError)(fn () => app(ScimUserMapper::class)->fromPatch(app(ScimPatchParser::class)->parse(($this->patch)([$operation])))))
        ->toBe([400, ScimErrorType::InvalidValue]);
})->with([
    [['op' => 'remove', 'path' => 'active']],
    [['op' => 'remove', 'path' => 'userName']],
    [['op' => 'replace', 'path' => 'active', 'value' => 'maybe']],
]);

it('reads a full user resource for create and replace', function (): void {
    $changes = app(ScimUserMapper::class)->fromResource([
        'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
        'userName' => ' Ada@Acme.DE ',
        'externalId' => '00u-ada',
        'active' => true,
        'name' => ['givenName' => 'Ada', 'familyName' => 'Lovelace'],
        'emails' => [['value' => 'other@acme.de', 'primary' => true]],
    ]);

    expect($changes->values)->toBe([
        'email' => 'ada@acme.de',
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'is_active' => true,
        'external_id' => '00u-ada',
    ]);
});

it('takes the primary email when the user name is no email address and refuses a resource without any', function (): void {
    $changes = app(ScimUserMapper::class)->fromResource(['userName' => 'ada', 'emails' => [['value' => 'x@acme.de'], ['value' => 'ada@acme.de', 'primary' => true]]]);

    expect($changes->values['email'])->toBe('ada@acme.de')
        ->and($changes->values['is_active'])->toBeTrue()
        ->and(($this->scimError)(fn () => app(ScimUserMapper::class)->fromResource(['userName' => 'ada'])))->toBe([400, ScimErrorType::InvalidValue]);
});
