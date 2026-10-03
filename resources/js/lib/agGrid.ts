import { AllCommunityModule, ModuleRegistry } from 'ag-grid-community';

export function registerAgGridModules(): void {
    ModuleRegistry.registerModules([AllCommunityModule]);

    if (import.meta.env.DEV) {
        void import('ag-grid-community').then(({ ValidationModule }) => {
            ModuleRegistry.registerModules([ValidationModule]);
        });
    }
}
