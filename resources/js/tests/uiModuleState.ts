import type { UiExtension, UiExtensionState, UiOptions } from '@/types/modules';

interface ModuleManifest {
    extensions?: Omit<UiExtension, 'module'>[];
    options?: UiOptions;
}

const manifests = import.meta.glob<ModuleManifest>(
    '../../../vendor/nubos/*/module.json',
    { eager: true, import: 'default' },
);

export function uiModuleState(
    modules: string[],
    page: Record<string, unknown> = {},
): UiExtensionState {
    const extensions: UiExtension[] = [];
    const options: UiOptions = {};

    for (const module of modules) {
        const manifest =
            manifests[`../../../vendor/${module}/module.json`] ?? {};

        for (const extension of manifest.extensions ?? []) {
            extensions.push({ ...extension, module });
        }

        for (const [point, entries] of Object.entries(manifest.options ?? {})) {
            options[point] = [...(options[point] ?? []), ...entries];
        }
    }

    return { modules, extensions, options, overrides: {}, page };
}
