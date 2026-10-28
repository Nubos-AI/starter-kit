import type { ComputedRef, Ref } from 'vue';
import { computed, ref, watch } from 'vue';
import { useUserPreferences } from '@/composables/useUserPreferences';
import type { Appearance, ResolvedAppearance } from '@/types';

export type { Appearance, ResolvedAppearance };

export type UseAppearanceReturn = {
    appearance: Ref<Appearance>;
    resolvedAppearance: ComputedRef<ResolvedAppearance>;
    updateAppearance: (value: Appearance) => void;
};

export function updateTheme(value: Appearance): void {
    if (typeof window === 'undefined') {
        return;
    }

    if (value === 'system') {
        const mediaQueryList = window.matchMedia(
            '(prefers-color-scheme: dark)',
        );
        const systemTheme = mediaQueryList.matches ? 'dark' : 'light';

        document.documentElement.classList.toggle(
            'dark',
            systemTheme === 'dark',
        );
    } else {
        document.documentElement.classList.toggle('dark', value === 'dark');
    }
}

const setCookie = (name: string, value: string, days = 365) => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;

    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

const mediaQuery = () => {
    if (typeof window === 'undefined') {
        return null;
    }

    return window.matchMedia('(prefers-color-scheme: dark)');
};

const readCookieAppearance = (): Appearance | null => {
    if (typeof document === 'undefined') {
        return null;
    }

    const match = document.cookie.match(/(?:^|;\s*)appearance=([^;]+)/);

    return match ? (decodeURIComponent(match[1]) as Appearance) : null;
};

const prefersDark = (): boolean => {
    if (typeof window === 'undefined') {
        return false;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
};

const handleSystemThemeChange = () => {
    updateTheme(appearance.value);
};

export function initializeTheme(): void {
    if (typeof window === 'undefined') {
        return;
    }

    appearance.value = readCookieAppearance() ?? 'system';
    updateTheme(appearance.value);

    mediaQuery()?.addEventListener('change', handleSystemThemeChange);
}

const appearance = ref<Appearance>('system');

export function useAppearance(): UseAppearanceReturn {
    const { settings, patch } = useUserPreferences();

    watch(
        () => settings.value.appearance,
        (value) => {
            appearance.value = value;
            updateTheme(value);
        },
        { immediate: true },
    );

    const resolvedAppearance = computed<ResolvedAppearance>(() => {
        if (appearance.value === 'system') {
            return prefersDark() ? 'dark' : 'light';
        }

        return appearance.value;
    });

    function updateAppearance(value: Appearance) {
        appearance.value = value;

        setCookie('appearance', value);

        updateTheme(value);
        patch({ settings: { appearance: value } });
    }

    return {
        appearance,
        resolvedAppearance,
        updateAppearance,
    };
}
