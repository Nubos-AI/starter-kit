import { createInertiaApp } from '@inertiajs/vue3';
import type { Component, DefineComponent } from 'vue';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import ObjectTypeLayout from '@/layouts/objectType/Layout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import { modulePages } from '@/lib/modules';
import { initializeTeamSegment } from '@/lib/teamSegment';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

const applicationPages = import.meta.glob<{ default: Component }>(
    './pages/**/*.vue',
);

createInertiaApp({
    resolve: async (name) => {
        const load =
            applicationPages[`./pages/${name}.vue`] ?? modulePages[name];

        if (!load) {
            throw new Error(`Unknown Inertia page [${name}].`);
        }

        const component = (await load()).default;

        if (typeof component !== 'object' && typeof component !== 'function') {
            throw new Error(`Invalid Inertia page [${name}].`);
        }

        return component as DefineComponent;
    },
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name.startsWith('errors/'):
                return null;
            case name === 'Welcome':
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            case name.startsWith('objectTypes/edit/'):
                return [AppLayout, ObjectTypeLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        color: 'var(--ds-bg-brand-bold)',
    },
});

initializeTheme();

initializeFlashToast();

initializeTeamSegment();
