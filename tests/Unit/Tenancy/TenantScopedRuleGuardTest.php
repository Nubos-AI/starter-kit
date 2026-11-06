<?php

declare(strict_types=1);

use PhpParser\Error as ParserError;
use Tests\Fixtures\Tenancy\TenantScopedRuleScanner;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->scanner = new TenantScopedRuleScanner;

    /** @var list<array{id: string, carrier: string, reason: string}> */
    $this->writePathExemptions = [
        [
            'id' => 'app/Actions/Teams/CreateTeamAction.php::execute::parent_team_id',
            'carrier' => 'app/Support/Teams/TeamInputValidator.php:20-25',
            'reason' => 'The written parent id comes from TeamInputValidator::parentOrFail(), which resolves the team by key while requalifying tenant_id.',
        ],
        [
            'id' => 'app/Http/Controllers/Notifications/NotificationRulesController.php::store::*',
            'carrier' => 'app/Actions/Notifications/SaveNotificationRuleAction.php:102-106',
            'reason' => 'The request payload only reaches SaveNotificationRuleAction::create(), which validates object_type_id and segment_id with tenant-qualified exists rules before writing.',
        ],
        [
            'id' => 'app/Http/Controllers/Notifications/NotificationRulesController.php::update::*',
            'carrier' => 'app/Actions/Notifications/SaveNotificationRuleAction.php:102-106',
            'reason' => 'The request payload only reaches SaveNotificationRuleAction::update(), which validates object_type_id and segment_id with tenant-qualified exists rules before writing.',
        ],
    ];

    $this->snippetGuardedTables = ['custom_records', 'field_groups', 'object_types', 'roles', 'teams'];

    $this->snippetForeignKeys = ['field_group_id', 'object_type_id', 'record_id', 'role_id', 'team_id'];

    $this->rulePath = 'app/Http/Requests/Probe/ProbeRequest.php';

    $this->ruleTemplate = <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace App\Http\Requests\Probe;

    use App\Models\ObjectType;
    use App\Models\Tenant;
    use App\Support\Tenancy\TenantContext;
    use Illuminate\Database\Query\Builder;
    use Illuminate\Support\Facades\Storage;
    __RULE_IMPORT__
    use Illuminate\Validation\Rules\Exists;

    class ProbeRequest
    {
        /**
         * @return array<string, mixed>
         */
        public function rules(string $table, string $tenantId, Tenant $tenant, object $disk): array
        {
    __BODY__
        }
    }
    PHP;

    $this->ruleMethodSource = fn (string $body, string $ruleImport = 'use Illuminate\Validation\Rule;'): string => str_replace(
        ['__RULE_IMPORT__', '__BODY__'],
        [$ruleImport, $body],
        $this->ruleTemplate,
    );

    $this->ruleSource = fn (string $rule, string $ruleImport = 'use Illuminate\Validation\Rule;'): string => ($this->ruleMethodSource)(
        "        return [\n            'probe' => [\n".$rule."\n            ],\n        ];",
        $ruleImport,
    );

    $this->writePath = 'app/Http/Controllers/Probe/ProbeController.php';

    $this->writeTemplate = <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace App\Http\Controllers\Probe;

    use App\Models\FieldGroup;
    use App\Models\ObjectType;
    use App\Models\User;
    use App\Scopes\TenantScope;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Validator;
    use Illuminate\Validation\Rule;

    class ProbeController
    {
        /**
         * @param  array<string, mixed>  $data
         * @param  array<string, mixed>  $input
         */
        public function store(Request $request, array $data, array $input, string $tenantId): void
        {
    __BODY__
        }
    }
    PHP;

    $this->writeSource = fn (string $body): string => str_replace('__BODY__', $body, $this->writeTemplate);

    $this->actionTemplate = <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace __NAMESPACE__;

    use App\Models\FieldGroup;

    class __CLASS__
    {
        public function __METHOD__(__PARAMETER__): FieldGroup
        {
            return FieldGroup::query()->create([
                '__KEY__' => __VARIABLE__,
                'name' => 'Probe',
            ]);
        }
    }
    PHP;

    $this->actionSource = fn (string $path, string $method, string $parameter, string $key): string => str_replace(
        ['__NAMESPACE__', '__CLASS__', '__METHOD__', '__PARAMETER__', '__KEY__', '__VARIABLE__'],
        [
            'App\\'.str_replace('/', '\\', dirname(substr($path, strlen('app/')))),
            basename($path, '.php'),
            $method,
            $parameter,
            $key,
            substr((string) strrchr($parameter, ' '), 1),
        ],
        $this->actionTemplate,
    );

    $this->lineOf = function (string $code, string $needle): int {
        $position = strpos($code, $needle);

        expect($position)->not->toBeFalse("Needle [{$needle}] is missing from the probe source.");

        return substr_count(substr($code, 0, (int) $position), "\n") + 1;
    };

    $this->siteList = static fn (array $sites): string => implode(', ', array_map(
        static fn (array $site): string => $site['path'].':'.$site['line'],
        $sites,
    ));

    $this->unqualifiedSites = static fn (array $sites): array => array_values(array_filter(
        $sites,
        static fn (array $site): bool => $site['qualified'] === false,
    ));

    $this->carrierPath = 'app/Support/Probe/ProbeResolver.php';

    $this->carrierSources = [
        'app/Support/Probe/ProbeResolver.php' => implode("\n", [
            '<?php',
            '',
            'namespace App\Support\Probe;',
            '',
            'class ProbeResolver',
            '{',
            '    public function resolve(string $objectTypeId): ObjectType',
            '    {',
            '        return ObjectType::query()->whereKey($objectTypeId)->firstOrFail();',
            '    }',
            '',
            '    public function scope(Builder $query, string $tenantId): Builder',
            '    {',
            "        return \$query->where('tenant_id', \$tenantId);",
            '    }',
            '}',
        ]),
    ];

    $this->probeFinding = [
        'id' => 'app/Actions/Probe/ProbeAction.php::execute::object_type_id',
        'path' => 'app/Actions/Probe/ProbeAction.php',
        'line' => 12,
        'method' => 'execute',
        'key' => 'object_type_id',
    ];
});

it('derives the guarded tables from TenantScope models plus role_permission, without users or tenants', function (): void {
    $tables = $this->scanner->guardedTables(app_path('Models'));

    expect($tables)
        ->toContain('object_types', 'custom_records', 'field_groups', 'teams', 'notification_inbox', 'role_permission')
        ->not->toContain('users')
        ->not->toContain('tenants')
        ->and($tables)->toBe(array_values(array_unique($tables)));
});

it('derives the guarded foreign keys from the migrations, including relation and hull keys but never tenant_id', function (): void {
    $tables = $this->scanner->guardedTables(app_path('Models'));

    $keys = $this->scanner->guardedForeignKeys(database_path('migrations'), $tables);

    expect($keys)
        ->toContain('object_type_id', 'record_id', 'field_group_id', 'parent_team_id')
        ->not->toContain('tenant_id')
        ->not->toContain('user_id')
        ->and($keys)->toBe(array_values(array_unique($keys)));
});

it('reads every php file below the subdirectory keyed by its project-relative path', function (): void {
    $sources = $this->scanner->sourcesIn(base_path(), 'app');

    $invalidPaths = array_values(array_filter(
        array_keys($sources),
        static fn (string $path): bool => !str_starts_with($path, 'app/') || !str_ends_with($path, '.php'),
    ));

    expect($sources)->toHaveKey('app/Actions/Engine/UpdateFieldDefinitionAction.php')
        ->and($sources['app/Actions/Engine/UpdateFieldDefinitionAction.php'])
        ->toBe(file_get_contents(app_path('Actions/Engine/UpdateFieldDefinitionAction.php')))
        ->and($invalidPaths)->toBe([], 'Source keys outside app/**.php: '.implode(', ', $invalidPaths));
});

it('reports an existence rule on a guarded table that does not qualify the tenant', function (string $rule, ?string $table): void {
    $code = ($this->ruleSource)($rule);

    $sites = $this->scanner->ruleSites([$this->rulePath => $code], $this->snippetGuardedTables);

    expect($sites)->toBe([[
        'path' => $this->rulePath,
        'line' => ($this->lineOf)($code, strtok($rule, "\n")),
        'table' => $table,
        'qualified' => false,
    ]]);
})->with([
    'string form, independent of the column' => ["'required|string|exists:object_types,slug'", 'object_types'],
    'string form in upper case' => ["'required|EXISTS:object_types,id'", 'object_types'],
    'string form with a connection prefix' => ["'exists:pgsql.object_types,id'", 'object_types'],
    'string form concatenated with a dynamic table' => ["'exists:'.\$table.',id'", null],
    'string form interpolated with a dynamic table' => ['"exists:{$table},id"', null],
    'object form with only a soft delete condition' => ["Rule::exists('object_types', 'id')->whereNull('deleted_at')", 'object_types'],
    'object form without a column' => ["Rule::exists('roles')", 'roles'],
    'object form with a connection prefix' => ["Rule::exists('pgsql.object_types', 'id')", 'object_types'],
    'object form resolving a model class' => ['Rule::exists(ObjectType::class)', 'object_types'],
    'string form resolving a model class string' => ["'required|exists:App\\Models\\ObjectType,id'", 'object_types'],
    'string form resolving a model class string behind a connection prefix' => ["'exists:pgsql.App\\Models\\ObjectType,id'", 'object_types'],
    'object form resolving a model class string' => ["Rule::exists('App\\Models\\ObjectType', 'id')", 'object_types'],
    'object form resolving a model class string with a leading backslash' => ["Rule::exists('\\App\\Models\\ObjectType', 'id')", 'object_types'],
    'new Exists resolving a model class string' => ["new Exists('App\\Models\\ObjectType')", 'object_types'],
    'unknown class string in the object form is checked as a dynamic table' => ["Rule::exists('App\\Models\\MissingProbeModel', 'id')", null],
    'unknown class string in the string form is checked as a dynamic table' => ["'required|exists:App\\Models\\MissingProbeModel,id'", null],
    'class string that is no model is checked as a dynamic table' => ["Rule::exists('App\\Scopes\\TenantScope', 'id')", null],
    'abstract model class string is checked as a dynamic table' => ["Rule::exists('Illuminate\\Database\\Eloquent\\Model', 'id')", null],
    'object form built with new Exists' => ["new Exists('roles', 'name')", 'roles'],
    'object form with a dynamic table' => ["Rule::exists(\$table, 'id')->whereNull('deleted_at')", null],
    'object form scoped by another column only (UpdateFieldDefinitionAction before the fix)' => [
        "Rule::exists('field_groups', 'id')\n                    ->where('object_type_id', \$table)\n                    ->whereNull('deleted_at')",
        'field_groups',
    ],
    'whereNot on tenant_id' => ["Rule::exists('object_types', 'id')->whereNot('tenant_id', \$tenantId)", 'object_types'],
    'whereNotIn on tenant_id' => ["Rule::exists('object_types', 'id')->whereNotIn('tenant_id', [\$tenantId])", 'object_types'],
    'whereIn on tenant_id' => ["Rule::exists('object_types', 'id')->whereIn('tenant_id', [\$tenantId])", 'object_types'],
    'whereNull on tenant_id' => ["Rule::exists('object_types', 'id')->whereNull('tenant_id')", 'object_types'],
    'where tenant_id null' => ["Rule::exists('object_types', 'id')->where('tenant_id', null)", 'object_types'],
    'where tenant_id equals null' => ["Rule::exists('object_types', 'id')->where('tenant_id', '=', null)", 'object_types'],
    'where tenant_id not equal' => ["Rule::exists('object_types', 'id')->where('tenant_id', '!=', \$tenantId)", 'object_types'],
    'where tenant_id with a literal array becomes whereIn' => ["Rule::exists('object_types', 'id')->where('tenant_id', [\$tenantId])", 'object_types'],
    'where tenant_id equals a literal array' => ["Rule::exists('object_types', 'id')->where('tenant_id', '=', [\$tenantId])", 'object_types'],
    'closure with a literal array tenant condition' => [
        "Rule::exists('object_types', 'id')->where(fn (Builder \$query): Builder => \$query->where('tenant_id', [\$tenantId]))",
        'object_types',
    ],
    'closure with a not-equal tenant condition' => [
        "Rule::exists('object_types', 'id')->where(fn (Builder \$query): Builder => \$query->where('tenant_id', '!=', \$tenantId))",
        'object_types',
    ],
    'closure that combines the tenant condition with orWhere' => [
        "Rule::exists('object_types', 'id')->where(fn (Builder \$query): Builder => \$query->where('tenant_id', \$tenantId)->orWhere('is_system', true))",
        'object_types',
    ],
    'condition on a column that only ends in tenant_id without a dot' => ["Rule::exists('object_types', 'id')->where('source_tenant_id', \$tenantId)", 'object_types'],
    'tenant condition only on a neighbouring rule' => [
        "Rule::unique('field_groups')->where('tenant_id', \$tenantId), Rule::exists('object_types', 'id')",
        'object_types',
    ],
]);

it('accepts an existence rule whose own expression qualifies the tenant', function (string $rule, ?string $table): void {
    $code = ($this->ruleSource)($rule);

    $sites = $this->scanner->ruleSites([$this->rulePath => $code], $this->snippetGuardedTables);

    expect($sites)->toBe([[
        'path' => $this->rulePath,
        'line' => ($this->lineOf)($code, strtok($rule, "\n")),
        'table' => $table,
        'qualified' => true,
    ]]);
})->with([
    'bound tenant from the context' => ["Rule::exists('object_types', 'id')->where('tenant_id', (string) TenantContext::currentId())", 'object_types'],
    'tenant id passed as a parameter' => ["Rule::exists('object_types', 'id')->where('tenant_id', \$tenantId)", 'object_types'],
    'tenant key of a tenant model' => ["Rule::exists('object_types', 'id')->where('tenant_id', (string) \$tenant->getKey())", 'object_types'],
    'explicit equals operator' => ["Rule::exists('object_types', 'id')->where('tenant_id', '=', \$tenantId)", 'object_types'],
    'table-qualified tenant column' => ["Rule::exists('object_types', 'id')->where('object_types.tenant_id', \$tenantId)", 'object_types'],
    'dynamic table with a tenant condition' => ["Rule::exists(\$table, 'id')->where('tenant_id', (string) TenantContext::currentId())", null],
    'model class string with a tenant condition' => ["Rule::exists('App\\Models\\ObjectType', 'id')->where('tenant_id', \$tenantId)", 'object_types'],
    'string rule for an unguarded table next to it is ignored' => [
        "'exists:users,id', Rule::exists('field_groups', 'id')->where('tenant_id', \$tenantId)",
        'field_groups',
    ],
    'multi-line chain with the tenant condition in the middle' => [
        "Rule::exists('field_groups', 'id')\n                    ->where('object_type_id', \$table)\n                    ->where('tenant_id', (string) TenantContext::currentId())\n                    ->whereNull('deleted_at')",
        'field_groups',
    ],
    'closure with the tenant condition as its third condition' => [
        "Rule::exists('roles', 'name')->where(\n                    fn (Builder \$query): Builder => \$query\n                        ->where('scope', 'tenant')\n                        ->whereNull('authority')\n                        ->where('tenant_id', \$tenantId),\n                )",
        'roles',
    ],
    'using closure with a statement body' => [
        "Rule::exists('roles', 'name')->using(function (Builder \$query) use (\$tenantId): void {\n                    \$query->where('tenant_id', \$tenantId);\n                })",
        'roles',
    ],
]);

it('reports the split variable form even when the tenant condition follows on the variable', function (): void {
    $body = implode("\n", [
        "        \$rule = Rule::exists('object_types', 'id');",
        "        \$rule->where('tenant_id', \$tenantId);",
        '',
        "        return ['probe' => [\$rule]];",
    ]);
    $code = ($this->ruleMethodSource)($body);

    $sites = $this->scanner->ruleSites([$this->rulePath => $code], $this->snippetGuardedTables);

    expect($sites)->toBe([[
        'path' => $this->rulePath,
        'line' => ($this->lineOf)($code, "\$rule = Rule::exists('object_types', 'id');"),
        'table' => 'object_types',
        'qualified' => false,
    ]]);
});

it('checks a qualified rule inside a private helper method with a fixed table', function (): void {
    $code = <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace App\Handlers\CustomFields;

    use App\Support\Tenancy\TenantContext;
    use Illuminate\Validation\Rule;
    use Illuminate\Validation\Rules\Exists;

    class ProbeFileFieldHandler
    {
        /**
         * @return list<mixed>
         */
        public function rules(): array
        {
            return ['array', $this->existsInBoundTenant()];
        }

        private function existsInBoundTenant(): Exists
        {
            return Rule::exists('custom_records', 'id')->where('tenant_id', (string) TenantContext::currentId());
        }
    }
    PHP;
    $path = 'app/Handlers/CustomFields/ProbeFileFieldHandler.php';

    $sites = $this->scanner->ruleSites([$path => $code], $this->snippetGuardedTables);

    expect($sites)->toBe([[
        'path' => $path,
        'line' => ($this->lineOf)($code, "return Rule::exists('custom_records', 'id')"),
        'table' => 'custom_records',
        'qualified' => true,
    ]]);
});

it('resolves the Rule class however it is referenced', function (string $ruleImport, string $rule): void {
    $code = ($this->ruleSource)($rule, $ruleImport);

    $sites = $this->scanner->ruleSites([$this->rulePath => $code], $this->snippetGuardedTables);

    expect($sites)->toBe([[
        'path' => $this->rulePath,
        'line' => ($this->lineOf)($code, $rule),
        'table' => 'roles',
        'qualified' => false,
    ]]);
})->with([
    'aliased import' => ['use Illuminate\Validation\Rule as R;', "R::exists('roles')"],
    'fully qualified call without an import' => ['', "\\Illuminate\\Validation\\Rule::exists('roles', 'name')"],
]);

it('does not treat unrelated or unguarded exists calls as rule candidates', function (string $rule): void {
    $code = ($this->ruleSource)($rule);

    expect($this->scanner->ruleSites([$this->rulePath => $code], $this->snippetGuardedTables))->toBe([]);
})->with([
    'object form on users' => ["Rule::exists('users', 'id')"],
    'object form on tenants' => ["Rule::exists('tenants', 'id')"],
    'string form on users' => ["'required|exists:users,id'"],
    'string form resolving the User model class string' => ["'required|exists:App\\Models\\User,id'"],
    'object form resolving the User model class string' => ["Rule::exists('App\\Models\\User', 'id')"],
    'filesystem exists on a disk instance' => ["\$disk->exists('object_types')"],
    'facade exists on storage' => ["Storage::exists('object_types')"],
    'validation message key ending in exists' => ["'field.exists' => 'The selected field is invalid.'"],
]);

it('never accepts an exemption for an unqualified rule', function (): void {
    $code = ($this->ruleSource)("'required|exists:object_types,id'");
    $sources = [$this->rulePath => $code] + $this->carrierSources;
    $line = ($this->lineOf)($code, "'required|exists:object_types,id'");

    $exemptions = [
        [
            'id' => $this->rulePath.'::rules::object_type_id',
            'carrier' => $this->carrierPath.':9',
            'reason' => 'Attempts to exempt a rule site by its identity.',
        ],
        [
            'id' => $this->rulePath.':'.$line,
            'carrier' => $this->carrierPath.':9',
            'reason' => 'Attempts to exempt a rule site by its location.',
        ],
    ];

    $violations = $this->scanner->exemptionViolations([], $exemptions, $sources);
    $sites = $this->scanner->ruleSites([$this->rulePath => $code], $this->snippetGuardedTables);

    expect($violations['orphaned'])->toBe([$this->rulePath.'::rules::object_type_id', $this->rulePath.':'.$line])
        ->and($violations['unlisted'])->toBe([])
        ->and($violations['unproven'])->toBe([])
        ->and(($this->unqualifiedSites)($sites))->toHaveCount(1)
        ->and((new ReflectionMethod(TenantScopedRuleScanner::class, 'ruleSites'))->getNumberOfParameters())->toBe(2);
});

it('fails loudly with the path when a source cannot be parsed for rule sites', function (): void {
    expect(fn () => $this->scanner->ruleSites(['app/Broken/ProbeBroken.php' => '<?php class {'], $this->snippetGuardedTables))
        ->toThrow(function (RuntimeException $exception): void {
            expect($exception->getMessage())->toContain('app/Broken/ProbeBroken.php')
                ->and($exception->getPrevious())->toBeInstanceOf(ParserError::class);
        });
});

it('fails loudly with the path when a source cannot be parsed for write paths', function (): void {
    expect(fn () => $this->scanner->writePathFindings(['app/Broken/ProbeBroken.php' => '<?php class {'], $this->snippetGuardedTables, $this->snippetForeignKeys))
        ->toThrow(function (RuntimeException $exception): void {
            expect($exception->getMessage())->toContain('app/Broken/ProbeBroken.php')
                ->and($exception->getPrevious())->toBeInstanceOf(ParserError::class);
        });
});

it('reports a write path that carries a guarded foreign key from input to a write without rule or scoped resolution', function (string $body, string $key): void {
    $code = ($this->writeSource)($body);

    $findings = $this->scanner->writePathFindings([$this->writePath => $code], $this->snippetGuardedTables, $this->snippetForeignKeys);

    expect(array_column($findings, 'id'))->toBe([$this->writePath.'::store::'.$key])
        ->and($findings[0]['path'])->toBe($this->writePath)
        ->and($findings[0]['method'])->toBe('store')
        ->and($findings[0]['key'])->toBe($key)
        ->and($findings[0]['line'])->toBeGreaterThan(($this->lineOf)($code, 'public function store'))
        ->and($findings[0]['line'])->toBeLessThanOrEqual(substr_count($code, "\n") + 1);
})->with([
    'request input straight into create' => [
        "        FieldGroup::query()->create(['object_type_id' => \$request->input('object_type_id'), 'name' => 'Probe']);",
        'object_type_id',
    ],
    'request get' => ["        FieldGroup::query()->create(['object_type_id' => \$request->get('object_type_id')]);", 'object_type_id'],
    'request post' => ["        FieldGroup::query()->create(['object_type_id' => \$request->post('object_type_id')]);", 'object_type_id'],
    'request query' => ["        FieldGroup::query()->create(['object_type_id' => \$request->query('object_type_id')]);", 'object_type_id'],
    'request string' => ["        FieldGroup::query()->create(['object_type_id' => (string) \$request->string('object_type_id')]);", 'object_type_id'],
    'request str' => ["        FieldGroup::query()->create(['object_type_id' => (string) \$request->str('object_type_id')]);", 'object_type_id'],
    'request integer' => ["        FieldGroup::query()->create(['record_id' => \$request->integer('record_id')]);", 'record_id'],
    'input array offset' => ["        FieldGroup::query()->create(['object_type_id' => \$input['object_type_id']]);", 'object_type_id'],
    'data array offset into update' => ["        FieldGroup::query()->whereKey('probe')->update(['team_id' => \$data['team_id']]);", 'team_id'],
    'taint across several assignments' => [
        implode("\n", [
            "        \$raw = \$request->input('object_type_id');",
            '        $fallback = $raw ?? null;',
            '        $objectTypeId = (string) $fallback;',
            "        FieldGroup::query()->create(['object_type_id' => \$objectTypeId]);",
        ]),
        'object_type_id',
    ],
    'ternary in the sink value' => [
        "        FieldGroup::query()->create(['object_type_id' => is_string(\$data['object_type_id'] ?? null) ? \$data['object_type_id'] : null]);",
        'object_type_id',
    ],
    'property assignment followed by save' => [
        implode("\n", [
            '        $fieldGroup = new FieldGroup;',
            "        \$fieldGroup->object_type_id = \$data['object_type_id'];",
            '        $fieldGroup->save();',
        ]),
        'object_type_id',
    ],
    'forceFill on a model' => [
        implode("\n", [
            '        $fieldGroup = new FieldGroup;',
            "        \$fieldGroup->forceFill(['object_type_id' => \$data['object_type_id']])->save();",
        ]),
        'object_type_id',
    ],
    'sink on a tenant scoped query chain does not carry itself' => [
        "        FieldGroup::query()->where('name', 'Probe')->updateOrCreate(['object_type_id' => \$data['object_type_id']]);",
        'object_type_id',
    ],
    'resolution through a model without TenantScope does not carry' => [
        implode("\n", [
            "        \$objectTypeId = \$request->input('object_type_id');",
            '        User::query()->whereKey($objectTypeId)->firstOrFail();',
            "        FieldGroup::query()->create(['object_type_id' => \$objectTypeId]);",
        ]),
        'object_type_id',
    ],
    'resolution without global scopes does not carry' => [
        implode("\n", [
            "        \$objectTypeId = \$request->input('object_type_id');",
            '        ObjectType::query()->withoutGlobalScopes()->whereKey($objectTypeId)->firstOrFail();',
            "        FieldGroup::query()->create(['object_type_id' => \$objectTypeId]);",
        ]),
        'object_type_id',
    ],
    'resolution without the tenant global scope does not carry' => [
        implode("\n", [
            "        \$objectTypeId = \$request->input('object_type_id');",
            '        ObjectType::query()->withoutGlobalScope(TenantScope::class)->whereKey($objectTypeId)->firstOrFail();',
            "        FieldGroup::query()->create(['object_type_id' => \$objectTypeId]);",
        ]),
        'object_type_id',
    ],
    'resolution through withoutTenantScope does not carry' => [
        implode("\n", [
            "        \$objectTypeId = \$request->input('object_type_id');",
            '        ObjectType::withoutTenantScope()->whereKey($objectTypeId)->firstOrFail();',
            "        FieldGroup::query()->create(['object_type_id' => \$objectTypeId]);",
        ]),
        'object_type_id',
    ],
    'unqualified rule for the key does not carry' => [
        implode("\n", [
            "        Validator::make(\$data, ['object_type_id' => ['required', Rule::exists('object_types', 'id')]])->validate();",
            "        FieldGroup::query()->create(['object_type_id' => \$data['object_type_id']]);",
        ]),
        'object_type_id',
    ],
    'qualified rule for a different key does not carry' => [
        implode("\n", [
            "        Validator::make(\$data, ['field_group_id' => [Rule::exists('field_groups', 'id')->where('tenant_id', \$tenantId)]])->validate();",
            "        FieldGroup::query()->create(['object_type_id' => \$data['object_type_id']]);",
        ]),
        'object_type_id',
    ],
    'request all straight into a bulk sink' => ['        FieldGroup::query()->create($request->all());', '*'],
    'request all through an assignment into a bulk sink' => [
        implode("\n", [
            '        $payload = $request->all();',
            '        FieldGroup::query()->create($payload);',
        ]),
        '*',
    ],
    'camelCase input key is normalised' => [
        "        FieldGroup::query()->create(['objectTypeId' => \$request->input('objectTypeId')]);",
        'object_type_id',
    ],
    'plural ids key is normalised' => [
        "        FieldGroup::query()->whereKey('probe')->update(['role_ids' => \$data['role_ids']]);",
        'role_id',
    ],
]);

it('does not report a write path that is carried or never reaches a write', function (string $body): void {
    $code = ($this->writeSource)($body);

    $findings = $this->scanner->writePathFindings([$this->writePath => $code], $this->snippetGuardedTables, $this->snippetForeignKeys);

    expect($findings)->toBe([], 'Unexpected findings: '.($this->siteList)($findings));
})->with([
    'carried by a tenant scoped resolution of the input variable' => [
        implode("\n", [
            "        \$objectTypeId = \$request->input('object_type_id');",
            '        ObjectType::query()->whereKey($objectTypeId)->firstOrFail();',
            "        FieldGroup::query()->create(['object_type_id' => \$objectTypeId]);",
        ]),
    ],
    'carried by a tenant scoped resolution of the inline input' => [
        implode("\n", [
            "        \$objectType = ObjectType::query()->whereKey(\$request->input('object_type_id'))->firstOrFail();",
            "        FieldGroup::query()->create(['object_type_id' => \$objectType->getKey()]);",
        ]),
    ],
    'carried by an unscoped resolution that qualifies the tenant again' => [
        implode("\n", [
            "        \$objectTypeId = \$request->input('object_type_id');",
            "        ObjectType::query()->withoutGlobalScopes()->where('tenant_id', \$tenantId)->whereKey(\$objectTypeId)->firstOrFail();",
            "        FieldGroup::query()->create(['object_type_id' => \$objectTypeId]);",
        ]),
    ],
    'carried by a qualified rule for the key in the same method' => [
        implode("\n", [
            "        Validator::make(\$data, ['object_type_id' => ['required', Rule::exists('object_types', 'id')->where('tenant_id', \$tenantId)]])->validate();",
            "        FieldGroup::query()->create(['object_type_id' => \$data['object_type_id']]);",
        ]),
    ],
    'carried by a qualified wildcard rule for the key' => [
        implode("\n", [
            "        Validator::make(\$data, ['object_type_id.*' => [Rule::exists('object_types', 'id')->where('tenant_id', \$tenantId)]])->validate();",
            "        FieldGroup::query()->create(['object_type_id' => \$data['object_type_id']]);",
        ]),
    ],
    'Validator make is no sink' => [
        "        Validator::make(['object_type_id' => \$request->input('object_type_id')], ['object_type_id' => ['required']])->validate();",
    ],
    'request all into Validator make is no sink' => [
        "        Validator::make(\$request->all(), ['object_type_id' => ['required']])->validate();",
    ],
    'make is no sink' => [
        "        FieldGroup::query()->make(['object_type_id' => \$data['object_type_id']]);",
    ],
    'key that is no guarded foreign key' => [
        "        FieldGroup::query()->create(['user_id' => \$request->input('user_id')]);",
    ],
    'validated array is no source' => [
        implode("\n", [
            '        $validated = $request->validated();',
            "        FieldGroup::query()->create(['object_type_id' => \$validated['object_type_id']]);",
        ]),
    ],
    'validated accessor is no source' => [
        "        FieldGroup::query()->create(['object_type_id' => \$request->validated('object_type_id')]);",
    ],
]);

it('treats only scalar execute parameters of actions as input sources', function (string $path, string $method, string $parameter, string $key, bool $isReported): void {
    $code = ($this->actionSource)($path, $method, $parameter, $key);

    $findings = $this->scanner->writePathFindings([$path => $code], $this->snippetGuardedTables, $this->snippetForeignKeys);

    expect(array_column($findings, 'id'))->toBe($isReported ? [$path.'::'.$method.'::'.$key] : []);
})->with([
    'nullable string parameter of an action execute method' => ['app/Actions/Probe/ProbeAction.php', 'execute', '?string $objectTypeId', 'object_type_id', true],
    'string parameter of an action execute method' => ['app/Actions/Probe/ProbeAction.php', 'execute', 'string $objectTypeId', 'object_type_id', true],
    'int parameter of an action execute method' => ['app/Actions/Probe/ProbeAction.php', 'execute', 'int $recordId', 'record_id', true],
    'execute method outside app/Actions' => ['app/Support/Probe/ProbeWriter.php', 'execute', '?string $objectTypeId', 'object_type_id', false],
    'other method inside app/Actions' => ['app/Actions/Probe/ProbeAction.php', 'handle', '?string $objectTypeId', 'object_type_id', false],
    'execute parameter that is no guarded foreign key' => ['app/Actions/Probe/ProbeAction.php', 'execute', '?string $userId', 'user_id', false],
]);

it('lists a finding without an exemption as unlisted', function (): void {
    $violations = $this->scanner->exemptionViolations([$this->probeFinding], [], $this->carrierSources);

    expect($violations['unlisted'])->toBe([$this->probeFinding['id']])
        ->and($violations['orphaned'])->toBe([])
        ->and($violations['unproven'])->toBe([]);
});

it('lists an exemption without a matching finding as orphaned', function (): void {
    $exemptions = [[
        'id' => $this->probeFinding['id'],
        'carrier' => $this->carrierPath.':9',
        'reason' => 'The object type is resolved through a tenant scoped query.',
    ]];

    $violations = $this->scanner->exemptionViolations([], $exemptions, $this->carrierSources);

    expect($violations['orphaned'])->toBe([$this->probeFinding['id']])
        ->and($violations['unlisted'])->toBe([])
        ->and($violations['unproven'])->toBe([]);
});

it('reports both directions at once when finding and exemption do not match', function (): void {
    $otherId = 'app/Actions/Probe/OtherProbeAction.php::execute::object_type_id';
    $exemptions = [[
        'id' => $otherId,
        'carrier' => $this->carrierPath.':9',
        'reason' => 'The object type is resolved through a tenant scoped query.',
    ]];

    $violations = $this->scanner->exemptionViolations([$this->probeFinding], $exemptions, $this->carrierSources);

    expect($violations['unlisted'])->toBe([$this->probeFinding['id']])
        ->and($violations['orphaned'])->toBe([$otherId])
        ->and($violations['unproven'])->toBe([]);
});

it('lists an exemption without a verifiable carrier or reason as unproven', function (string $carrier, string $reason): void {
    $exemptions = [[
        'id' => $this->probeFinding['id'],
        'carrier' => $carrier,
        'reason' => $reason,
    ]];

    $violations = $this->scanner->exemptionViolations([$this->probeFinding], $exemptions, $this->carrierSources);

    expect($violations['unproven'])->toBe([$this->probeFinding['id']])
        ->and($violations['unlisted'])->toBe([])
        ->and($violations['orphaned'])->toBe([]);
})->with([
    'empty carrier' => ['', 'The object type is resolved through a tenant scoped query.'],
    'carrier without a line' => ['app/Support/Probe/ProbeResolver.php', 'The object type is resolved through a tenant scoped query.'],
    'carrier outside app' => ['Support/Probe/ProbeResolver.php:9', 'The object type is resolved through a tenant scoped query.'],
    'carrier with a non numeric line' => ['app/Support/Probe/ProbeResolver.php:nine', 'The object type is resolved through a tenant scoped query.'],
    'carrier pointing at line zero' => ['app/Support/Probe/ProbeResolver.php:0', 'The object type is resolved through a tenant scoped query.'],
    'carrier line behind the end of the file' => ['app/Support/Probe/ProbeResolver.php:17', 'The object type is resolved through a tenant scoped query.'],
    'carrier range running past the end of the file' => ['app/Support/Probe/ProbeResolver.php:15-17', 'The object type is resolved through a tenant scoped query.'],
    'carrier file missing from the sources' => ['app/Support/Probe/MissingResolver.php:9', 'The object type is resolved through a tenant scoped query.'],
    'carrier range without a scoped query or tenant condition' => ['app/Support/Probe/ProbeResolver.php:1-7', 'The object type is resolved through a tenant scoped query.'],
    'empty reason' => ['app/Support/Probe/ProbeResolver.php:9', ''],
    'whitespace-only reason' => ['app/Support/Probe/ProbeResolver.php:9', '   '],
]);

it('accepts a valid exemption for an existing finding', function (string $carrier): void {
    $exemptions = [[
        'id' => $this->probeFinding['id'],
        'carrier' => $carrier,
        'reason' => 'The object type is resolved through a tenant scoped query.',
    ]];

    $violations = $this->scanner->exemptionViolations([$this->probeFinding], $exemptions, $this->carrierSources);

    expect($violations)->toBe(['unlisted' => [], 'orphaned' => [], 'unproven' => []]);
})->with([
    'single line with a scoped query' => ['app/Support/Probe/ProbeResolver.php:9'],
    'single line with a tenant condition' => ['app/Support/Probe/ProbeResolver.php:14'],
    'range around a scoped query' => ['app/Support/Probe/ProbeResolver.php:8-10'],
    'range ending on the last line of the file' => ['app/Support/Probe/ProbeResolver.php:14-16'],
]);

it('finds rule sites across app and none of them leaves the tenant unqualified', function (): void {
    $tables = $this->scanner->guardedTables(app_path('Models'));
    $sites = $this->scanner->ruleSites($this->scanner->sourcesIn(base_path(), 'app'), $tables);

    $unqualified = ($this->unqualifiedSites)($sites);
    $unguardedTableSites = array_values(array_filter(
        $sites,
        static fn (array $site): bool => in_array($site['table'], ['users', 'tenants'], true),
    ));
    $dynamicRoleInputSites = array_values(array_filter(
        $sites,
        static fn (array $site): bool => $site['path'] === 'app/Support/Authorization/RoleInputRules.php'
            && $site['table'] === null
            && $site['qualified'] === true,
    ));

    expect(count($sites))->toBeGreaterThan(0)
        ->and($dynamicRoleInputSites)->toHaveCount(1)
        ->and($unguardedTableSites)->toBe([], 'Sites on unguarded tables must be skipped: '.($this->siteList)($unguardedTableSites))
        ->and($unqualified)->toBe([], 'Existence rules without a tenant_id condition in the same rule expression (split variable forms count as unqualified, there is no exemption): '.($this->siteList)($unqualified));
});

it('keeps every write path in app either carried or covered by a proven exemption', function (): void {
    $tables = $this->scanner->guardedTables(app_path('Models'));
    $keys = $this->scanner->guardedForeignKeys(database_path('migrations'), $tables);
    $sources = $this->scanner->sourcesIn(base_path(), 'app');

    $findings = $this->scanner->writePathFindings($sources, $tables, $keys);
    $violations = $this->scanner->exemptionViolations($findings, $this->writePathExemptions, $sources);

    $locations = [];

    foreach ($findings as $finding) {
        $locations[$finding['id']] = $finding['path'].':'.$finding['line'];
    }

    $unlisted = array_map(static fn (string $id): string => ($locations[$id] ?? '?').' '.$id, $violations['unlisted']);

    expect($violations['unlisted'])->toBe([], 'Write paths without rule, scoped resolution or exemption: '.implode(', ', $unlisted))
        ->and($violations['orphaned'])->toBe([], 'Exemptions without a matching finding, remove them: '.implode(', ', $violations['orphaned']))
        ->and($violations['unproven'])->toBe([], 'Exemptions without a verifiable path:line carrier or reason: '.implode(', ', $violations['unproven']));
});
