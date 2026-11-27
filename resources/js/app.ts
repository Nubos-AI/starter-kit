import { createInertiaApp } from '@inertiajs/vue3';
import type { Component, DefineComponent } from 'vue';
import { AppLayout, AuthLayout, SettingsLayout, initializeFlashToast } from '@/app-shell';
import { ObjectTypeLayout } from '@/domains/business-data/ui-object-types';
import { initializeTeamSegment } from '@/domains/workspace-access/data';
import { TeamLayout } from '@/domains/workspace-access/ui-teams';
import { initializeTheme } from '@/shared/composables/useAppearance';
import { modulePages } from '@/shared/lib/modules';
import { pageLoader, pageRegistry } from '@/shared/lib/pages';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

const applicationPages = pageRegistry(
  import.meta.glob<{ default: Component }>([
    './domains/*/*/pages/**/*.vue',
    './platform/*/pages/**/*.vue',
    './app-shell/pages/**/*.vue',
  ]),
);

createInertiaApp({
  resolve: async (name) => {
    // eslint-disable-next-line no-console
    const load = pageLoader(name, applicationPages, modulePages, import.meta.env.DEV ? console.info : undefined);

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
      case name.startsWith('teams/edit/'):
        return [AppLayout, TeamLayout];
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
