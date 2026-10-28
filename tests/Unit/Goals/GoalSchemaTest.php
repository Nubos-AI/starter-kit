<?php

declare(strict_types=1);

use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->goals = SchemaShape::ofMigration('database/migrations/0001_01_01_000050_create_goals_table.php');
    $this->goalsDown = SchemaShape::ofMigration('database/migrations/0001_01_01_000050_create_goals_table.php', 'down');
    $this->periods = SchemaShape::ofMigration('database/migrations/0001_01_01_000051_create_goal_periods_table.php');
    $this->periodsDown = SchemaShape::ofMigration('database/migrations/0001_01_01_000051_create_goal_periods_table.php', 'down');
});

it('rejects every inconsistent combination of scope and target at the database level', function (): void {
    $constraint = $this->goals->statementMatching('chk_goals_scope_target');

    expect($constraint)->not->toBeNull()
        ->and($constraint)->toContain("scope_type = 'user' AND target_user_id IS NOT NULL AND target_team_id IS NULL AND scope_field_key IS NOT NULL")
        ->and($constraint)->toContain("scope_type = 'team' AND target_team_id IS NOT NULL AND target_user_id IS NULL AND scope_field_key IS NOT NULL")
        ->and($constraint)->toContain("scope_type = 'tenant' AND target_user_id IS NULL AND target_team_id IS NULL AND scope_field_key IS NULL");
});

it('leaves the period field outside the scope target invariant so a snapshot goal stays legal', function (): void {
    expect($this->goals->statementMatching('chk_goals_scope_target'))->not->toContain('period_field_key')
        ->and($this->goals->columnDefinition('goals', 'period_field_key'))->toContain('null');
});

it('holds one period per goal and start so a second tick overwrites instead of duplicating', function (): void {
    expect($this->periods->has('alter table "goal_periods" add constraint "unq_goal_periods_goal_period_start" unique ("goal_id", "period_start")'))
        ->toBeTrue();
});

it('keeps the goal columns in the order the conventions demand', function (): void {
    expect($this->goals->columnsOf('goals'))->toBe([
        'id',
        'tenant_id',
        'owner_id',
        'report_id',
        'target_user_id',
        'target_team_id',
        'includes_subteams',
        'name',
        'scope_type',
        'scope_field_key',
        'period_field_key',
        'period_type',
        'direction',
        'target_value',
        'deleted_at',
        'created_at',
        'updated_at',
    ]);
});

it('keys goals and their periods by ulid and never by an autoincrementing integer', function (): void {
    expect($this->goals->columnDefinition('goals', 'id'))->toContain('char(26)')
        ->and($this->periods->columnDefinition('goal_periods', 'id'))->toContain('char(26)')
        ->and($this->goals->has('alter table "goals" add primary key ("id")'))->toBeTrue()
        ->and($this->periods->has('alter table "goal_periods" add primary key ("id")'))->toBeTrue();
});

it('stores the target and the recorded value with four decimal places', function (): void {
    expect($this->goals->createStatementFor('goals'))->toContain('"target_value" decimal(20, 4) not null')
        ->and($this->periods->createStatementFor('goal_periods'))->toContain('"current_value" decimal(20, 4) null');
});

it('leaves the recorded value and its moment empty until a tick fills them', function (): void {
    expect($this->periods->createStatementFor('goal_periods'))->toContain('"current_value" decimal(20, 4) null')
        ->and($this->periods->columnDefinition('goal_periods', 'calculated_at'))->toContain('null');
});

it('carries the triggered thresholds as a json object that defaults to empty', function (): void {
    expect($this->periods->columnDefinition('goal_periods', 'triggered_thresholds'))->toContain('jsonb')
        ->and($this->periods->columnDefinition('goal_periods', 'triggered_thresholds'))->toContain("default '{}'");
});

it('lets a goal be soft deleted while its periods are removed with it', function (): void {
    expect($this->goals->columnsOf('goals'))->toContain('deleted_at')
        ->and($this->periods->columnsOf('goal_periods'))->not->toContain('deleted_at')
        ->and($this->periods->hasForeignKey('goal_periods', 'goal_id', 'goals', 'cascade'))->toBeTrue();
});

it('binds every goal and every period to its tenant with a cascading key', function (): void {
    expect($this->goals->hasForeignKey('goals', 'tenant_id', 'tenants', 'cascade'))->toBeTrue()
        ->and($this->periods->hasForeignKey('goal_periods', 'tenant_id', 'tenants', 'cascade'))->toBeTrue();
});

it('removes a goal whose report, owner or target is deleted', function (): void {
    expect($this->goals->hasForeignKey('goals', 'report_id', 'reports', 'cascade'))->toBeTrue()
        ->and($this->goals->hasForeignKey('goals', 'owner_id', 'users', 'cascade'))->toBeTrue()
        ->and($this->goals->hasForeignKey('goals', 'target_user_id', 'users', 'cascade'))->toBeTrue()
        ->and($this->goals->hasForeignKey('goals', 'target_team_id', 'teams', 'cascade'))->toBeTrue();
});

it('lets a widget point at a goal and drops that link again on the way down', function (): void {
    expect($this->goals->hasForeignKey('dashboard_widgets', 'goal_id', 'goals', 'cascade'))->toBeTrue()
        ->and($this->goalsDown->has('alter table "dashboard_widgets" drop constraint "dashboard_widgets_goal_id_foreign"'))->toBeTrue()
        ->and($this->goalsDown->has('drop table if exists "goals"'))->toBeTrue()
        ->and($this->periodsDown->has('drop table if exists "goal_periods"'))->toBeTrue();
});
