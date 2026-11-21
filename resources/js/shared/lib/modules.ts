import { usePage } from '@inertiajs/vue3';
import type { Component } from 'vue';
import { pageRegistry } from '@/shared/lib/pages';
import type { ModuleComponentLoader } from '@/shared/types/modules';
import type { SelectOption } from '@/shared/types/ui';

const componentFiles = import.meta.glob<{ default: Component }>([
  '../../../../vendor/nubos/*/resources/js/domains/*/*/ui/**/*.vue',
  '../../../../vendor/nubos/*/resources/js/extensions/*/ui/**/*.vue',
  '../../../../vendor/nubos/*/resources/js/shared/ui/**/*.vue',
]);

export const modulePages = pageRegistry(
  import.meta.glob<{ default: Component }>('../../../../vendor/nubos/*/resources/js/domains/*/*/pages/**/*.vue'),
);

export function moduleComponent(module: string, component: string): ModuleComponentLoader {
  const load = componentFiles[`../../../../vendor/${module}/resources/js/${component}`];

  if (!load) {
    throw new Error(`Unknown module component [${module}:${component}].`);
  }

  return load;
}

export function sharedModuleOptions(point: string): SelectOption[] {
  return usePage().props?.uiOptions?.[point] ?? [];
}
