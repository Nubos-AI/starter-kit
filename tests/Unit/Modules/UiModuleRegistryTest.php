<?php

declare(strict_types=1);

use App\Contracts\Modules\UiModuleInterface;
use App\Models\User;
use App\Support\Modules\UiModuleRegistry;
use Illuminate\Http\Request;
use Tests\Support\Doubles\FixtureModuleCatalog;
use Tests\Support\Doubles\StaticModuleRegistry;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    /** @var callable(string, array<string, mixed>, list<array{target: string, items: list<array<string, mixed>>}>):UiModuleInterface */
    $this->uiModule = static fn (string $key, array $shared = [], array $navigation = []): UiModuleInterface => new class($key, $shared, $navigation) implements UiModuleInterface
    {
        /**
         * @param  array<string, mixed>  $shared
         * @param  list<array{target: string, items: list<array<string, mixed>>}>  $navigation
         */
        public function __construct(
            private readonly string $key,
            private readonly array $shared,
            private readonly array $navigation,
        ) {}

        public function key(): string
        {
            return $this->key;
        }

        /**
         * @return array<string, mixed>
         */
        public function share(Request $request): array
        {
            return $this->shared;
        }

        /**
         * @param  array<string, bool>  $permissions
         * @return list<array{target: string, items: list<array<string, mixed>>}>
         */
        public function navigation(User $user, array $permissions): array
        {
            return $this->navigation;
        }
    };

    /** @var callable(list<string>, list<UiModuleInterface>):UiModuleRegistry */
    $this->registryOver = fn (array $registered, array $modules = []): UiModuleRegistry => new UiModuleRegistry(
        $modules,
        new StaticModuleRegistry($registered),
        new FixtureModuleCatalog,
    );
});

it('shares the extensions and options of a registered module and names their owner', function (): void {
    $shared = ($this->registryOver)(['nubos/example'])->share(Request::create('/'));

    expect($shared['uiModules'])->toBe(['nubos/example'])
        ->and(collect($shared['uiExtensions'])->firstWhere('id', 'nubos/example.tab'))->toBe([
            'id' => 'nubos/example.tab',
            'point' => 'tabs.record-detail-tabs.triggers',
            'component' => 'components/ExampleTab.vue',
            'order' => 100,
            'module' => 'nubos/example',
        ])
        ->and(array_column($shared['uiOptions']['permissions.groups'], 'value'))->toContain('example-templates');
});

it('hides the extensions and options of a module the registry does not carry', function (): void {
    $shared = ($this->registryOver)([])->share(Request::create('/'));

    expect($shared['uiModules'])->toBe([])
        ->and($shared['uiExtensions'])->toBe([])
        ->and($shared['uiOptions'])->toBe([]);
});

it('hides a registered module that the package discovery no longer knows', function (): void {
    $shared = ($this->registryOver)(['nubos/example', 'nubos/ghost'])->share(Request::create('/'));

    expect($shared['uiModules'])->toBe(['nubos/example']);
});

it('lets a registered filter withhold a module from the frontend', function (): void {
    $registry = ($this->registryOver)(['nubos/example']);
    $registry->filterUsing(static fn (string $name, ?User $user): bool => $name !== 'nubos/example');

    expect($registry->share(Request::create('/'))['uiModules'])->toBe([]);
});

it('merges the shared payload of every visible ui module into the page props', function (): void {
    $registry = ($this->registryOver)(
        ['nubos/example'],
        [($this->uiModule)('nubos/example', ['exampleTemplates' => ['a', 'b']])],
    );

    expect($registry->share(Request::create('/'))['exampleTemplates'])->toBe(['a', 'b']);
});

it('leaves the shared payload of a module the registry withholds out of the page props', function (): void {
    $registry = ($this->registryOver)([], [($this->uiModule)('nubos/example', ['exampleTemplates' => ['a']])]);

    expect($registry->share(Request::create('/')))->not->toHaveKey('exampleTemplates');
});

it('refuses a shared property that would shadow one the application already sends', function (): void {
    $registry = ($this->registryOver)(
        ['nubos/example'],
        [($this->uiModule)('nubos/example', ['uiModules' => ['hijacked']])],
    );

    expect(fn (): mixed => $registry->share(Request::create('/')))
        ->toThrow(LogicException::class, 'Duplicate shared module property [uiModules].');
});

it('refuses two ui modules that claim the same key', function (): void {
    expect(fn (): mixed => ($this->registryOver)([], [
        ($this->uiModule)('nubos/example'),
        ($this->uiModule)('nubos/example'),
    ]))->toThrow(LogicException::class, 'Duplicate UI module [nubos/example].');
});

it('lets a registered module extend a nested navigation node it does not own', function (): void {
    $registry = ($this->registryOver)(['nubos/example'], [($this->uiModule)('nubos/example', [], [
        ['target' => 'navigation.node.deep', 'items' => [['key' => 'example.visible', 'label' => 'Default', 'href' => '/example']]],
    ])]);

    $extended = $registry->extendNavigation([
        'main' => [['items' => [['key' => 'deep', 'children' => [['key' => 'core', 'label' => 'Core']]]]]],
        'configuration' => [],
    ], new User, []);

    expect($extended['main'][0]['items'][0]['children'])->toBe([
        ['key' => 'core', 'label' => 'Core'],
        ['key' => 'example.visible', 'label' => 'Default', 'href' => '/example'],
    ]);
});

it('lets the deployment relabel, reorder and switch off a contributed navigation item', function (): void {
    config(['modules.navigation' => [
        'example.visible' => ['label' => 'My label', 'order' => 1],
        'example.hidden' => ['enabled' => false],
    ]]);

    $registry = ($this->registryOver)(['nubos/example'], [($this->uiModule)('nubos/example', [], [
        ['target' => 'navigation.node.deep', 'items' => [
            ['key' => 'example.visible', 'label' => 'Default', 'href' => '/example'],
            ['key' => 'example.hidden', 'label' => 'Hidden', 'href' => '/hidden'],
        ]],
    ])]);

    $extended = $registry->extendNavigation([
        'main' => [['items' => [['key' => 'deep', 'children' => [['key' => 'core', 'label' => 'Core']]]]]],
        'configuration' => [],
    ], new User, []);

    expect($extended['main'][0]['items'][0]['children'])->toBe([
        ['key' => 'example.visible', 'label' => 'My label', 'href' => '/example', 'order' => 1],
        ['key' => 'core', 'label' => 'Core'],
    ]);
});

it('lets a module relabel the record section and switch off entries inside it', function (): void {
    $registry = ($this->registryOver)(['nubos/example'], [($this->uiModule)('nubos/example', [], [
        ['target' => 'navigation.overrides', 'items' => [
            ['key' => 'records', 'label' => 'Vertrieb'],
            ['key' => 'record-invoices', 'enabled' => false],
        ]],
    ])]);

    $extended = $registry->extendNavigation([
        'main' => [['key' => 'records', 'label' => 'Records', 'items' => [
            ['key' => 'record-companies', 'label' => 'Companies'],
            ['key' => 'record-invoices', 'label' => 'Invoices'],
        ]]],
        'configuration' => [],
    ], new User, []);

    expect($extended['main'])->toBe([
        ['key' => 'records', 'label' => 'Vertrieb', 'items' => [['key' => 'record-companies', 'label' => 'Companies']]],
    ]);
});

it('drops a main section once every entry in it is switched off', function (): void {
    $registry = ($this->registryOver)(['nubos/example'], [($this->uiModule)('nubos/example', [], [
        ['target' => 'navigation.overrides', 'items' => [['key' => 'record-invoices', 'enabled' => false]]],
    ])]);

    $extended = $registry->extendNavigation([
        'main' => [
            ['label' => '', 'items' => [['key' => 'dashboard', 'label' => 'Dashboard']]],
            ['key' => 'records', 'label' => 'Records', 'items' => [['key' => 'record-invoices', 'label' => 'Invoices']]],
        ],
        'configuration' => [],
    ], new User, []);

    expect($extended['main'])->toBe([
        ['label' => '', 'items' => [['key' => 'dashboard', 'label' => 'Dashboard']]],
    ]);
});

it('refuses a contribution aimed at a navigation node that does not exist', function (): void {
    $registry = ($this->registryOver)(['nubos/example'], [($this->uiModule)('nubos/example', [], [
        ['target' => 'navigation.node.nowhere', 'items' => [['key' => 'example', 'label' => 'Example']]],
    ])]);

    expect(fn (): mixed => $registry->extendNavigation(['main' => [], 'configuration' => []], new User, []))
        ->toThrow(LogicException::class, 'Unknown navigation extension target [navigation.node.nowhere].');
});

it('leaves the navigation untouched when no module is registered', function (): void {
    $tree = ['main' => [], 'configuration' => []];

    expect(($this->registryOver)([])->extendNavigation($tree, new User, []))->toBe($tree);
});
