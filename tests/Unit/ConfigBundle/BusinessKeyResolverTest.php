<?php

declare(strict_types=1);

use App\Support\ConfigBundle\BusinessKeyResolver;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->rows = [
        'object_types' => [['id' => ModelStub::ulid('deal-type'), 'key' => 'deal']],
        'widgets' => [
            ['id' => ModelStub::ulid('typed-widget'), 'object_type_id' => ModelStub::ulid('deal-type'), 'name' => 'Pipeline'],
            ['id' => ModelStub::ulid('untyped-widget'), 'object_type_id' => null, 'name' => 'Overview'],
        ],
    ];
});

it('drops the key of a row without an object type while its table is not registered as optionally qualified', function (): void {
    config(['engine.config_bundle.optionally_qualified_tables' => ['segments']]);

    $resolver = new BusinessKeyResolver($this->rows);

    expect($resolver->keyFor('widgets', ModelStub::ulid('typed-widget')))->toBe('deal:Pipeline')
        ->and($resolver->keyFor('widgets', ModelStub::ulid('untyped-widget')))->toBeNull();
});

it('keys a row without an object type under the absent reference once a module registers its table', function (): void {
    config(['engine.config_bundle.optionally_qualified_tables' => ['segments', 'widgets']]);

    $resolver = new BusinessKeyResolver($this->rows);
    $absent = config('engine.config_bundle.reserved_tokens.absent_reference');

    expect($resolver->keyFor('widgets', ModelStub::ulid('typed-widget')))->toBe('deal:Pipeline')
        ->and($resolver->keyFor('widgets', ModelStub::ulid('untyped-widget')))->toBe("{$absent}:Overview");
});
