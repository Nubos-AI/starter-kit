import { computed, inject, provide } from 'vue';
import type { ComputedRef, InjectionKey } from 'vue';
import type { UiExtensionState } from '@/types/modules';

const extensionStateKey: InjectionKey<ComputedRef<UiExtensionState>> =
    Symbol('ui-extensions');

export function provideUiExtensions(
    state: ComputedRef<UiExtensionState>,
): void {
    provide(extensionStateKey, state);
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
