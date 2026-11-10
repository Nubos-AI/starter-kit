<?php

declare(strict_types=1);

use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->schema = SchemaShape::ofMigration('database/migrations/0001_01_01_000040_create_webhook_subscriptions_table.php');
});

it('places the optional team right after the tenant so a subscription can act for one team or the whole system', function (): void {
    expect(array_slice($this->schema->columnsOf('webhook_subscriptions'), 0, 3))->toBe(['id', 'tenant_id', 'team_id'])
        ->and($this->schema->columnDefinition('webhook_subscriptions', 'team_id'))->toStartWith('char(26)')
        ->and($this->schema->columnDefinition('webhook_subscriptions', 'team_id'))->not->toContain('not null');
});

it('removes a team scoped subscription together with its team so it can never widen to the whole system', function (): void {
    expect($this->schema->hasForeignKey('webhook_subscriptions', 'team_id', 'teams', 'cascade'))->toBeTrue()
        ->and($this->schema->has('create index "webhook_subscriptions_team_id_index"'))->toBeTrue();
});
