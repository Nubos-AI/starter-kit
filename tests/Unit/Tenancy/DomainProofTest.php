<?php

declare(strict_types=1);

use App\Enums\Tenancy\DomainProofPurpose;
use App\Models\SsoConnection;
use App\Support\Tenancy\DomainProof;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
});

it('keeps the TXT record single sign-on domains were proven with', function (): void {
    $tenantId = ModelStub::ulid('acme');
    $expected = hash_hmac('sha256', "sso-domain:{$tenantId}:acme.de", (string) config('app.key'));

    expect(DomainProof::recordFor(DomainProofPurpose::Sso, $tenantId, 'acme.de'))
        ->toBe(['name' => '_nubos-sso.acme.de', 'value' => "nubos-sso-verification={$expected}"])
        ->and(ModelStub::make(SsoConnection::class, ['tenant_id' => $tenantId])->domainProofRecord('acme.de'))
        ->toBe(DomainProof::recordFor(DomainProofPurpose::Sso, $tenantId, 'acme.de'));
});

it('asks every tenant for its own TXT record on an own site domain', function (): void {
    $acme = DomainProof::recordFor(DomainProofPurpose::Site, ModelStub::ulid('acme'), 'acme.de');
    $rival = DomainProof::recordFor(DomainProofPurpose::Site, ModelStub::ulid('rival'), 'acme.de');

    expect($acme['name'])->toBe('_nubos-site.acme.de')
        ->and($acme['value'])->toStartWith('nubos-site-verification=')
        ->and($rival['name'])->toBe($acme['name'])
        ->and($rival['value'])->not->toBe($acme['value'])
        ->and($acme['value'])->not->toBe(DomainProof::recordFor(DomainProofPurpose::Sso, ModelStub::ulid('acme'), 'acme.de')['value']);
});
