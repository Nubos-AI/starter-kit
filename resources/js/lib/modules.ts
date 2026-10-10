import { usePage } from '@inertiajs/vue3';
import type { Component } from 'vue';
import type { ModuleComponentLoader } from '@/types/modules';
import type { SelectOption } from '@/types/ui';

const PAGES_DIRECTORY = 'resources/js/pages/';

const pageFiles = import.meta.glob<{ default: Component }>(
    '../../../vendor/nubos/*/resources/js/pages/**/*.vue',
);

const componentFiles = import.meta.glob<{ default: Component }>(
    '../../../vendor/nubos/*/resources/js/components/**/*.vue',
);

export const modulePages = Object.entries(pageFiles).reduce<
    Record<string, ModuleComponentLoader>
>((pages, [path, load]) => {
    const name = path.slice(
        path.indexOf(PAGES_DIRECTORY) + PAGES_DIRECTORY.length,
        -'.vue'.length,
    );

    if (name in pages) {
        throw new Error(`Duplicate module page [${name}].`);
    }

    pages[name] = load;

    return pages;
}, {});

export function moduleComponent(
    module: string,
    component: string,
): ModuleComponentLoader {
    const load =
        componentFiles[`../../../vendor/${module}/resources/js/${component}`];

    if (!load) {
        throw new Error(`Unknown module component [${module}:${component}].`);
    }

    return load;
}

export function sharedModuleOptions(point: string): SelectOption[] {
    return usePage().props?.uiOptions?.[point] ?? [];
}
