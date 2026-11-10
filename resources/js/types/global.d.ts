import type { TranslationCatalogue } from '@/lib/i18n';
import type { Auth } from '@/types/auth';
import type { MaintenanceState } from '@/types/maintenance';
import type {
    UiExtension,
    UiExtensionOverride,
    UiOptions,
} from '@/types/modules';
import type { NavNode, NavSection } from '@/types/navigation';
import type { PreferenceDocument } from '@/types/preferences';
import type { TeamSummary } from '@/types/teams';

declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: {
            <T>(pattern: string): Record<string, () => Promise<T>>;
            <T>(
                pattern: string,
                options: { eager: true; import: 'default' },
            ): Record<string, T>;
        };
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            i18n: TranslationCatalogue;
            auth: Auth;
            navigation: { main?: NavSection[]; configuration?: NavNode[] };
            currentTeam: TeamSummary | null;
            availableTeams: TeamSummary[];
            uiModules: string[];
            uiExtensions: UiExtension[];
            uiOptions: UiOptions;
            uiExtensionOverrides: Record<string, UiExtensionOverride>;
            registration?: { requiresCompanyName: boolean };
            contextKey: string;
            maintenance: MaintenanceState | null;
            reminderTypeOptions: Array<{ value: string; label: string }>;
            sidebarOpen: boolean;
            preferences: PreferenceDocument | null;
            vapidPublicKey: string;
        };
    }
}

declare module 'vue' {
    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
