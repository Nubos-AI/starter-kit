import { beforeEach, describe, expect, it, vi } from 'vitest';
import { usePermissions } from '@/shared/composables/usePermissions';

const { pageState } = vi.hoisted(() => ({
  pageState: {
    props: {
      auth: {
        user: null,
        can: {} as Record<string, unknown>,
        authority: null as unknown,
      },
    },
    url: '/dashboard',
  } as { props: unknown; url: string },
}));

vi.mock('@inertiajs/vue3', () => ({
  usePage: () => pageState,
}));

function grant(...names: string[]): void {
  pageState.props = {
    auth: {
      user: null,
      can: Object.fromEntries(names.map((name) => [name, true])),
      authority: null,
    },
  };
}

beforeEach(() => {
  grant();
});

describe('usePermissions', () => {
  it('grants only a boolean true verdict and denies malformed maps', () => {
    grant('roles.view');

    expect(usePermissions().can('roles.view')).toBe(true);
    expect(usePermissions().can('roles.create')).toBe(false);

    const malformedProps: unknown[] = [
      { auth: { user: null, can: {}, authority: null } },
      {},
      undefined,
      { auth: { user: null, can: 'everything', authority: null } },
    ];

    for (const props of malformedProps) {
      pageState.props = props;

      expect(usePermissions().can('roles.create')).toBe(false);
    }

    const looseVerdicts: unknown[] = ['true', 1, {}, [], null, undefined, 'false', 0, ''];

    for (const verdict of looseVerdicts) {
      pageState.props = {
        auth: {
          user: null,
          can: { 'roles.create': verdict },
          authority: null,
        },
      };

      expect(usePermissions().can('roles.create')).toBe(false);
    }

    grant('companies.rules.manage');

    const { canForObjectType } = usePermissions();

    expect(canForObjectType('companies', 'rules.manage')).toBe(true);
    expect(canForObjectType('contacts', 'rules.manage')).toBe(false);
    expect(canForObjectType('companies', 'audit.view')).toBe(false);
  });
});
