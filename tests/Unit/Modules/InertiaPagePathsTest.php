<?php

declare(strict_types=1);

use App\Support\Modules\InertiaPagePaths;
use Illuminate\Filesystem\Filesystem;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/nubos-pages-'.bin2hex(random_bytes(8));
    $this->files = new Filesystem;
    $this->files->makeDirectory("{$this->root}/domains/workspace-access/feature-auth/pages", 0755, true);
    $this->files->makeDirectory("{$this->root}/domains/workspace-access/feature-users/pages", 0755, true);
    $this->files->makeDirectory("{$this->root}/domains/workspace-access/ui-users/ui", 0755, true);
    $this->files->makeDirectory("{$this->root}/platform/feature-maintenance/pages", 0755, true);
    $this->files->makeDirectory("{$this->root}/app-shell/pages", 0755, true);
});

afterEach(function (): void {
    $this->files->deleteDirectory($this->root);
});

it('lists the pages folder of every domain module, the platform modules and the app shell', function (): void {
    expect(InertiaPagePaths::discover($this->root))->toBe([
        "{$this->root}/app-shell/pages",
        "{$this->root}/domains/workspace-access/feature-auth/pages",
        "{$this->root}/domains/workspace-access/feature-users/pages",
        "{$this->root}/platform/feature-maintenance/pages",
    ]);
});

it('ignores a flat pages folder outside the modules', function (): void {
    $this->files->makeDirectory("{$this->root}/pages");

    expect(InertiaPagePaths::discover($this->root))->toBe([
        "{$this->root}/app-shell/pages",
        "{$this->root}/domains/workspace-access/feature-auth/pages",
        "{$this->root}/domains/workspace-access/feature-users/pages",
        "{$this->root}/platform/feature-maintenance/pages",
    ]);
});

it('answers no path for a root without pages', function (): void {
    expect(InertiaPagePaths::discover("{$this->root}/missing"))->toBe([]);
});

it('appends the pages of a module to the configured paths once', function (): void {
    config(['inertia.pages.paths' => ['/core/pages']]);

    InertiaPagePaths::register($this->root);
    InertiaPagePaths::register($this->root);

    expect(config('inertia.pages.paths'))->toBe([
        '/core/pages',
        "{$this->root}/app-shell/pages",
        "{$this->root}/domains/workspace-access/feature-auth/pages",
        "{$this->root}/domains/workspace-access/feature-users/pages",
        "{$this->root}/platform/feature-maintenance/pages",
    ]);
});
