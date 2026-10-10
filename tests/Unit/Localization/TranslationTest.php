<?php

declare(strict_types=1);

use App\Http\Middleware\SetLocale;
use App\Support\Localization\JsonTranslationLoader;
use App\Support\Localization\TranslationCatalogue;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Translation\Translator;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Doubles\StaticModuleRegistry;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->app->instance(ModuleRegistry::class, new StaticModuleRegistry);
});

it('shares the same nested core and package messages with PHP and Vue', function (): void {
    $catalogue = app(TranslationCatalogue::class)->forLocale('en');
    expect(app('translation.loader'))->toBeInstanceOf(JsonTranslationLoader::class);

    expect($catalogue['messages'])->toHaveKey('i18n');

    foreach ($catalogue['messages'] as $group => $messages) {
        $leaves = Arr::dot($messages);
        foreach ($leaves as $key => $value) {
            expect(__($group.'.'.$key, [], 'en'))->toBe($value);
        }
    }
});

it('keeps German and English catalogue keys and placeholders in sync', function (): void {
    $files = [resource_path('lang/de/i18n.json'), ...glob(base_path('packages/nubos/*/resources/lang/de/i18n.json'))];
    foreach ($files as $file) {
        $de = Arr::dot(json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR));
        $en = Arr::dot(json_decode(file_get_contents(str_replace('/de/', '/en/', $file)), true, flags: JSON_THROW_ON_ERROR));
        expect(array_keys($en))->toBe(array_keys($de));
        foreach ($de as $key => $value) {
            expect($value)->toBeString()->not->toBe('');
            expect($en[$key])->toBeString()->not->toBe('');
            preg_match_all('/(?<![a-zA-Z0-9]):[a-zA-Z_][a-zA-Z0-9_]*|%[ds]/', $value, $left);
            preg_match_all('/(?<![a-zA-Z0-9]):[a-zA-Z_][a-zA-Z0-9_]*|%[ds]/', $en[$key], $right);
            $left[0] = array_values(array_unique($left[0]));
            $right[0] = array_values(array_unique($right[0]));
            sort($left[0]);
            sort($right[0]);
            expect($right[0])->toBe($left[0], $file.': '.$key);
        }
    }
});

it('loads nested JSON with fallback and package overrides without losing PHP translations', function (): void {
    $root = sys_get_temp_dir().'/nubos-i18n-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    try {
        foreach (['de', 'en', 'package/de', 'vendor/demo/de'] as $path) {
            $files->makeDirectory($root.'/'.$path, 0755, true);
        }
        $files->put($root.'/de/i18n.json', '{"greeting":"Hallo :name"}');
        $files->put($root.'/en/i18n.json', '{"fallback":"English fallback"}');
        $files->put($root.'/de/validation.php', '<?php return ["required" => "Pflichtfeld"];');
        $files->put($root.'/package/de/i18n.json', '{"title":"Original","untouched":"Package"}');
        $files->put($root.'/vendor/demo/de/i18n.json', '{"title":"Override"}');
        $loader = new JsonTranslationLoader($files, $root);
        $loader->addNamespace('demo', $root.'/package');
        $translator = new Translator($loader, 'de');
        $translator->setFallback('en');
        expect($translator->get('i18n.greeting', ['name' => 'Ralf']))->toBe('Hallo Ralf')
            ->and($translator->get('i18n.fallback'))->toBe('English fallback')
            ->and($translator->get('validation.required'))->toBe('Pflichtfeld')
            ->and($translator->get('demo::i18n.title'))->toBe('Override')
            ->and($translator->get('demo::i18n.untouched'))->toBe('Package')
            ->and($translator->get('i18n.missing'))->toBe('i18n.missing');
    } finally {
        $files->deleteDirectory($root);
    }
});

it('selects the supported request language on every request', function (): void {
    config(['app.supported_locales' => ['de', 'en'], 'app.locale' => 'de']);
    foreach (['en-US,en;q=0.9' => 'en', 'de-DE' => 'de', 'fr' => 'de'] as $header => $expected) {
        $request = Request::create('/');
        $request->headers->set('Accept-Language', $header);
        (new SetLocale)->handle($request, function () use ($expected): Response {
            expect(app()->getLocale())->toBe($expected);

            return new Response;
        });
    }
});

it('prefers a supported session language and ignores an unsupported one', function (): void {
    config(['app.supported_locales' => ['de', 'en']]);
    $request = Request::create('/');
    $request->headers->set('Accept-Language', 'en');
    $request->setLaravelSession(app('session.store'));
    foreach (['de' => 'de', 'invalid' => 'en'] as $sessionLocale => $expected) {
        $request->session()->put('locale', $sessionLocale);
        (new SetLocale)->handle($request, function () use ($expected): Response {
            expect(app()->getLocale())->toBe($expected);

            return new Response;
        });
    }
});

it('resolves every literal message key used by the application', function (): void {
    $messages = app(TranslationCatalogue::class)->forLocale('de')['messages'];
    $known = [];
    foreach ($messages as $group => $tree) {
        foreach (Arr::dot($tree) as $key => $value) {
            $known[$group.'.'.$key] = true;
        }
    }
    $missing = [];
    $roots = [
        ...array_map(base_path(...), ['app', 'bootstrap', 'config', 'resources/js']),
        ...glob(base_path('vendor/nubos/*'), GLOB_ONLYDIR),
    ];
    foreach ($roots as $root) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'vue', 'ts'], true)
                || preg_match('~/tests/|/vendor/|\.spec\.ts$~', substr($file->getPathname(), strlen($root)))) {
                continue;
            }
            preg_match_all('/[\'"]((?:[a-z_]+::)?i18n\.[a-z0-9_.]+)[\'"]/', file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $key) {
                if (!isset($known[$key])) {
                    $missing[] = $file->getPathname().': '.$key;
                }
            }
        }
    }
    expect($missing)->toBe([]);
});

it('returns to the configured default language after an English request', function (): void {
    config(['app.default_locale' => 'de', 'app.supported_locales' => ['de', 'en']]);
    app()->setLocale('en');
    $request = Request::create('/');
    $request->headers->remove('Accept-Language');
    (new SetLocale)->handle($request, function (): Response {
        expect(app()->getLocale())->toBe('de');

        return new Response;
    });
    config(['app.default_locale' => 'en']);
    $request = Request::create('/');
    $request->headers->remove('Accept-Language');
    (new SetLocale)->handle($request, function (): Response {
        expect(app()->getLocale())->toBe('en');

        return new Response;
    });
});

it('serves the requested catalogue with an Inertia page', function (string $locale): void {
    $this->get('/login', ['Accept-Language' => $locale])
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('i18n.locale', $locale)
            ->where('i18n.messages.i18n.framework.auth.failed', __('auth.failed', [], $locale)));
})->with(['de', 'en']);

it('uses the shared JSON catalogue for Laravel validation messages', function (string $locale): void {
    app()->setLocale($locale);
    $message = __('validation.required', ['attribute' => __('validation.attributes.email')]);
    $this->postJson('/login', [], ['Accept-Language' => $locale])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', $message);
})->with(['de', 'en']);
