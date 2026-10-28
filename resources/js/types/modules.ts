import type { Component } from 'vue';
import type { SelectOption } from '@/types/ui';

export type ModuleComponentLoader = () => Promise<{ default: Component }>;

export interface UiExtension {
    id: string;
    module: string;
    point: string;
    component: string;
    order: number;
    props?: Record<string, unknown>;
}

export interface UiExtensionOverride {
    enabled?: boolean;
    point?: string;
    order?: number;
    props?: Record<string, unknown>;
}

export type UiOptions = Record<string, SelectOption[]>;

export interface UiExtensionState {
    modules: string[];
    extensions: UiExtension[];
    options: UiOptions;
    overrides: Record<string, UiExtensionOverride>;
    page: Record<string, unknown>;
}
