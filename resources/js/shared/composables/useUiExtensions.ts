import { usePage } from '@inertiajs/vue3';
import { computed, inject, provide, toValue } from 'vue';
import type { ComputedRef, InjectionKey, MaybeRefOrGetter } from 'vue';
import type { UiExtension, UiExtensionState } from '@/shared/types/modules';

const extensionStateKey: InjectionKey<ComputedRef<UiExtensionState>> = Symbol('ui-extensions');

export function provideUiExtensions(state: ComputedRef<UiExtensionState>): void {
  provide(extensionStateKey, state);
}

export function provideSharedUiExtensions(): void {
  const page = usePage();

  provideUiExtensions(
    computed(() => ({
      modules: page.props.uiModules ?? [],
      extensions: page.props.uiExtensions ?? [],
      options: page.props.uiOptions ?? {},
      overrides: page.props.uiExtensionOverrides ?? {},
      page: page.props,
    })),
  );
}

export function useUiExtensions(): ComputedRef<UiExtensionState> {
  return inject(
    extensionStateKey,
    computed(() => ({
      modules: [],
      extensions: [],
      options: {},
      overrides: {},
      page: {},
    })),
  );
}

export function extensionsAt(state: UiExtensionState, point: string): UiExtension[] {
  return state.extensions
    .map((extension) => ({
      ...extension,
      ...(state.overrides[extension.id] ?? {}),
    }))
    .filter((extension) => extension.enabled !== false && extension.point === point)
    .sort((first, second) => first.order - second.order || first.id.localeCompare(second.id));
}

export function useRowActionsPoint(point: MaybeRefOrGetter<string | undefined>): ComputedRef<string | undefined> {
  const state = useUiExtensions();

  return computed(() => {
    const name = toValue(point);

    return name !== undefined && extensionsAt(state.value, name).length > 0 ? name : undefined;
  });
}
