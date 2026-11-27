import { usePage } from '@inertiajs/vue3';
import type { Component } from 'vue';
import { pageRegistry } from '@/shared/lib/pages';
import type { ModuleComponentLoader } from '@/shared/types/modules';
import type { SelectOption } from '@/shared/types/ui';

const applicationModule = 'app';

const vendorPath = /^\/vendor\/([^/]+\/[^/]+)\/(?:module\.json|resources\/js\/(.+))$/;

const manifests = import.meta.glob<number>('/vendor/*/*/module.json', { eager: true, import: 'apiVersion' });

export function installedModuleFiles<T>(
  files: Record<string, T>,
  manifestPaths: Record<string, unknown>,
): Record<string, T> {
  const modules = new Set(Object.keys(manifestPaths).map((path) => vendorPath.exec(path)?.[1]));

  return Object.fromEntries(Object.entries(files).filter(([path]) => modules.has(vendorPath.exec(path)?.[1])));
}

export function componentRegistry(
  vendorFiles: Record<string, ModuleComponentLoader>,
  applicationFiles: Record<string, ModuleComponentLoader>,
): Record<string, ModuleComponentLoader> {
  const components: Record<string, ModuleComponentLoader> = {};

  for (const [path, load] of Object.entries(vendorFiles)) {
    const [, module, component] = vendorPath.exec(path) ?? [];

    if (module !== undefined && component !== undefined) {
      components[`${module}:${component}`] = load;
    }
  }

  for (const [path, load] of Object.entries(applicationFiles)) {
    components[`${applicationModule}:${path.replace(/^\/resources\/js\//, '')}`] = load;
  }

  return components;
}

const componentFiles = componentRegistry(
  installedModuleFiles(
    import.meta.glob<{ default: Component }>([
      '/vendor/*/*/resources/js/domains/*/*/ui/**/*.vue',
      '/vendor/*/*/resources/js/extensions/*/ui/**/*.vue',
      '/vendor/*/*/resources/js/shared/ui/**/*.vue',
    ]),
    manifests,
  ),
  import.meta.glob<{ default: Component }>('/resources/js/extensions/**/*.vue'),
);

export const modulePages = pageRegistry(
  installedModuleFiles(
    import.meta.glob<{ default: Component }>('/vendor/*/*/resources/js/domains/*/*/pages/**/*.vue'),
    manifests,
  ),
);

export function moduleComponent(module: string, component: string): ModuleComponentLoader {
  const load = componentFiles[`${module}:${component}`];

  if (!load) {
    throw new Error(`Unknown module component [${module}:${component}].`);
  }

  return load;
}

export function sharedModuleOptions(point: string): SelectOption[] {
  return usePage().props?.uiOptions?.[point] ?? [];
}
