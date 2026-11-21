<?php

declare(strict_types=1);

use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->schema = SchemaShape::ofMigration('database/migrations/0001_01_01_000040_create_webhook_subscriptions_table.php');
});

it('removes a team scoped subscription together with its team so it can never widen to the whole system', function (): void {
    expect($this->schema->hasForeignKey('webhook_subscriptions', 'team_id', 'teams', 'cascade'))->toBeTrue()
        ->and($this->schema->has('create index "webhook_subscriptions_team_id_index"'))->toBeTrue();
});
