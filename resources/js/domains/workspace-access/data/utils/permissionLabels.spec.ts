import { afterEach, describe, expect, it, vi } from 'vitest';
import { t } from '@/shared/testing/i18n';
import type { UiOptions } from '@/shared/types/modules';
import { permissionActionLabel, permissionGroupLabel } from './permissionLabels';

const page = vi.hoisted(() => ({
  props: { uiOptions: {} as UiOptions },
}));

vi.mock('@inertiajs/vue3', () => ({
  usePage: () => page,
}));

describe('permissionLabels — keys without a configured label', () => {
  it('still reads an unknown action instead of dropping it', () => {
    expect(permissionActionLabel('members.frobnicate', 'members', t)).toBe('Frobnicate');
  });

  it('reads an object type group key that carries no configured label', () => {
    expect(permissionGroupLabel('companies', t)).toBe('Companies');
    expect(permissionGroupLabel('service-contracts', t)).toBe('Service Contracts');
  });
});

describe('permissionLabels — a module names its own actions', () => {
  afterEach(() => {
    page.props.uiOptions = {};
  });

  it('prefers the label a module contributes for the full permission name', () => {
    page.props.uiOptions = {
      'permissions.actions': [
        {
          value: 'calendar.manage',
          label: 'Alle Buchungsseiten verwalten',
        },
      ],
    };

    expect(permissionActionLabel('calendar.manage', 'calendar', t)).toBe('Alle Buchungsseiten verwalten');
  });

  it('keeps the default label for an action the module does not name', () => {
    page.props.uiOptions = {
      'permissions.actions': [
        {
          value: 'calendar.manage',
          label: 'Alle Buchungsseiten verwalten',
        },
      ],
    };

    expect(permissionActionLabel('calendar.view', 'calendar', t)).toBe(t('i18n.lib.permission_labels.actions.view'));
    expect(permissionActionLabel('members.manage', 'members', t)).toBe(t('i18n.lib.permission_labels.actions.manage'));
  });
});

describe('permissionLabels — a module names its own permission groups', () => {
  afterEach(() => {
    page.props.uiOptions = {};
  });

  it('reads the group label a module contributes for a group the core does not know', () => {
    page.props.uiOptions = {
      'permissions.groups': [{ value: 'calendar', label: 'Kalender und Buchungen' }],
    };

    expect(permissionGroupLabel('calendar', t)).toBe('Kalender und Buchungen');
    expect(permissionGroupLabel('service-contracts', t)).toBe('Service Contracts');
  });

  it('keeps the core label of a group even when a module names it differently', () => {
    page.props.uiOptions = {
      'permissions.groups': [{ value: 'promotions', label: 'Fremdes Etikett' }],
    };

    expect(permissionGroupLabel('promotions', t)).toBe(t('i18n.lib.permission_labels.groups.promotions'));
  });
});
