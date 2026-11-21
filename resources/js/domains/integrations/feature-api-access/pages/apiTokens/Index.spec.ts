import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { gridColumn, gridOf, rowAction, selectionCheckbox, selectRow } from '@/shared/testing/dataGrid';
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue';
import SelectionBulkBar from '@/shared/ui/data-grid/SelectionBulkBar.vue';
import { setUrlDefaults } from '@/wayfinder';
import Index from './Index.vue';

setUrlDefaults({ activeTeam: 'nubos' });

const { visitMock, postMock, deleteMock, toastErrorMock } = vi.hoisted(() => ({
  visitMock: vi.fn(),
  postMock: vi.fn(),
  deleteMock: vi.fn(),
  toastErrorMock: vi.fn(),
}));

vi.mock('vue-sonner', () => ({
  toast: { error: toastErrorMock, success: vi.fn() },
}));

vi.mock('@inertiajs/vue3', async () => ({
  ...(await import('@/shared/testing/inertia')).inertiaStubs,
  router: { visit: visitMock, post: postMock, delete: deleteMock },
}));

interface ApiTokenRow {
  id: string;
  name: string;
  abilities: string[];
  ownerName: string | null;
  ownerEmail: string | null;
  isService: boolean;
  lastUsedAt: string | null;
  expiresAt: string | null;
  createdAt: string | null;
  canRevoke: boolean;
  revokeReason: string | null;
}

type Wrapper = ReturnType<typeof mount>;

function token(overrides: Partial<ApiTokenRow> = {}): ApiTokenRow {
  return {
    id: '1',
    name: 'CI-Integration',
    abilities: ['records:read'],
    ownerName: 'Integration Dienst',
    ownerEmail: 'integration@example.com',
    isService: true,
    lastUsedAt: null,
    expiresAt: null,
    createdAt: null,
    canRevoke: true,
    revokeReason: null,
    ...overrides,
  };
}

function mountIndex(tokens: ApiTokenRow[] = [token()], secret: string | null = null): Wrapper {
  return mount(Index, { props: { tokens, secret } });
}

beforeEach(() => {
  visitMock.mockReset();
  postMock.mockReset();
  deleteMock.mockReset();
});

describe('apiTokens/Index', () => {
  it('hides the grid while no token exists', () => {
    const wrapper = mountIndex([]);

    expect(gridOf(wrapper).exists()).toBe(false);
  });

  it('shows the bulk bar only once a row is selected', async () => {
    const wrapper = mountIndex([token(), token({ id: '2' })]);

    expect(wrapper.findComponent(SelectionBulkBar).props('count')).toBe(0);

    await selectRow(wrapper, token({ id: '2' }));

    expect(wrapper.findComponent(SelectionBulkBar).props('count')).toBe(1);
  });

  it('posts the selected ids to the bulk delete route', async () => {
    const wrapper = mountIndex([token(), token({ id: '2' })]);

    await selectRow(wrapper, token({ id: '1' }));
    await selectRow(wrapper, token({ id: '2' }));

    wrapper.findComponent(SelectionBulkBar).vm.$emit('delete');
    await nextTick();

    expect(postMock).toHaveBeenCalledWith(
      '/nubos/engine/api-tokens/bulk-delete',
      { ids: ['1', '2'] },
      expect.anything(),
    );
  });

  it('drops a selection whose row disappeared', async () => {
    const wrapper = mountIndex([token(), token({ id: '2' })]);

    await selectRow(wrapper, token({ id: '1' }));
    await selectRow(wrapper, token({ id: '2' }));

    await wrapper.setProps({ tokens: [token({ id: '2' })] });

    expect(wrapper.findComponent(SelectionBulkBar).props('count')).toBe(1);
  });

  it('asks before rotating and posts the rotation afterwards', async () => {
    const wrapper = mountIndex();

    rowAction<ApiTokenRow>(wrapper, 'Rotieren').onClick?.(token());
    await nextTick();

    expect(postMock).not.toHaveBeenCalled();

    const dialog = wrapper.findAllComponents(ConfirmDialog).find((entry) => entry.props('open') === true)!;

    dialog.vm.$emit('confirm');
    await nextTick();

    expect(postMock).toHaveBeenCalledWith('/nubos/engine/api-tokens/1/rotate', {}, expect.anything());
  });

  it('surfaces the refusal of a rotation as an error toast', async () => {
    const wrapper = mountIndex();

    rowAction<ApiTokenRow>(wrapper, 'Rotieren').onClick?.(token());
    await nextTick();

    wrapper
      .findAllComponents(ConfirmDialog)
      .find((entry) => entry.props('open') === true)!
      .vm.$emit('confirm');
    await nextTick();

    const options = postMock.mock.calls.at(-1)?.[2] as {
      onError: (errors: Record<string, string>) => void;
    };

    options.onError({
      'objectTypeAccess.0.levels':
        'Ein Token darf nicht mehr Rechte gewähren, als Sie selbst für diesen Objekttyp haben.',
    });

    expect(toastErrorMock).toHaveBeenCalledWith(
      'Ein Token darf nicht mehr Rechte gewähren, als Sie selbst für diesen Objekttyp haben.',
    );

    options.onError({});

    expect(toastErrorMock).toHaveBeenLastCalledWith(
      'Das Token konnte nicht rotiert werden. Bitte versuchen Sie es erneut.',
    );
  });

  it('asks before revoking and deletes the token afterwards', async () => {
    const wrapper = mountIndex();

    rowAction<ApiTokenRow>(wrapper, 'Widerrufen').onClick?.(token());
    await nextTick();

    expect(deleteMock).not.toHaveBeenCalled();

    const dialog = wrapper.findAllComponents(ConfirmDialog).find((entry) => entry.props('open') === true)!;

    dialog.vm.$emit('confirm');
    await nextTick();

    expect(deleteMock).toHaveBeenCalledWith('/nubos/engine/api-tokens/1', expect.anything());
  });

  it('reveals a freshly flashed secret once and hides it again on dismiss', async () => {
    mountIndex([token()], 'token-plain-text-secret');
    await nextTick();

    expect(document.body.querySelector('[data-api-token-secret]')?.textContent).toContain('token-plain-text-secret');

    const done = [...document.body.querySelectorAll('button')].find(
      (button) => button.textContent?.trim() === 'Fertig',
    )!;

    done.click();
    await flushPromises();

    expect(document.body.querySelector('[data-api-token-secret]')).toBeNull();
  });
});

describe('apiTokens/Index owner and origin', () => {
  it('reads the owner name for a personal token', () => {
    const wrapper = mountIndex([token({ isService: false, ownerName: 'Ada Lovelace' })]);
    const column = gridColumn(wrapper, 'owner');

    expect(
      (column.valueGetter as (params: unknown) => string)({
        data: token({ isService: false, ownerName: 'Ada Lovelace' }),
      }),
    ).toBe('Ada Lovelace');
  });

  it('falls the owner back to the email address', () => {
    const wrapper = mountIndex();
    const column = gridColumn(wrapper, 'owner');

    expect(
      (column.valueGetter as (params: unknown) => string)({
        data: token({ ownerName: null, ownerEmail: 'a@b.example' }),
      }),
    ).toBe('a@b.example');
  });

  it('labels the origin of a service and a personal token', () => {
    const wrapper = mountIndex();
    const column = gridColumn(wrapper, 'origin');
    const read = column.valueGetter as (params: unknown) => string;

    expect(read({ data: token({ isService: true }) })).toBe('Dienst');
    expect(read({ data: token({ isService: false }) })).toBe('Persönlich');
  });

  it('disables rotation for a personal token and names the reason', () => {
    const rotate = rowAction<ApiTokenRow>(mountIndex(), 'Rotieren');
    const service = token({ isService: true });
    const personal = token({ isService: false });

    expect(rotate.isVisible).toBeUndefined();
    expect(rotate.isDisabled?.(service)).toBe(false);
    expect(rotate.disabledReason?.(service)).toBeUndefined();
    expect(rotate.isDisabled?.(personal)).toBe(true);
    expect(rotate.disabledReason?.(personal)).toBe(
      'Nur Dienst-Token lassen sich rotieren. Ein persönliches Token widerrufen Sie und legen es neu an.',
    );
  });
});

describe('apiTokens/Index revocation guard', () => {
  const blocked = token({
    id: '9',
    isService: false,
    ownerName: 'Inhaberin',
    canRevoke: false,
    revokeReason: 'Dieses Konto hat mehr Rechte als Sie.',
  });

  it('disables revocation of a token the server blocked and names the reason', () => {
    const revoke = rowAction<ApiTokenRow>(mountIndex([token(), blocked]), 'Widerrufen');

    expect(revoke.isDisabled?.(token())).toBe(false);
    expect(revoke.disabledReason?.(token())).toBeUndefined();
    expect(revoke.isDisabled?.(blocked)).toBe(true);
    expect(revoke.disabledReason?.(blocked)).toBe('Dieses Konto hat mehr Rechte als Sie.');
  });

  it('keeps a token the server blocked out of the bulk selection', () => {
    const wrapper = mountIndex([token(), blocked]);

    expect(selectionCheckbox(wrapper, blocked).exists()).toBe(false);
    expect(selectionCheckbox(wrapper, token()).exists()).toBe(true);
  });

  it('drops a selected token once the server blocks it', async () => {
    const wrapper = mountIndex([token(), token({ id: '9' })]);

    await selectRow(wrapper, token({ id: '1' }));
    await selectRow(wrapper, token({ id: '9' }));

    await wrapper.setProps({ tokens: [token(), blocked] });

    expect(wrapper.findComponent(SelectionBulkBar).props('count')).toBe(1);
  });
});
