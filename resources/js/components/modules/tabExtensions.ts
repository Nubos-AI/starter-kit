import type { ComputedRef, InjectionKey } from 'vue';

export interface TabExtensionContext {
    point?: string;
    context?: object;
}

export const tabExtensionKey: InjectionKey<ComputedRef<TabExtensionContext>> =
    Symbol('tab-extensions');
