import { usePage } from '@inertiajs/vue3';
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
