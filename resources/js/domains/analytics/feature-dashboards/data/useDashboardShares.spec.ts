import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import DashboardsController from '@/actions/App/Http/Controllers/Dashboards/DashboardsController';
import DashboardShareOptionsController from '@/actions/App/Http/Controllers/Dashboards/DashboardShareOptionsController';
import DashboardSharesController from '@/actions/App/Http/Controllers/Dashboards/DashboardSharesController';
import { t } from '@/shared/testing/i18n';
import { serveEmpty, serveJson, serveNetworkError, serveServerError, serveValidationError } from '@/shared/testing/msw';
import { setUrlDefaults } from '@/wayfinder';
import {
  OPTIONS_LOAD_ERROR_MESSAGE,
  SHARE_CREATE_ERROR_MESSAGE,
  SHARE_REVOKE_ERROR_MESSAGE,
  SHARES_LOAD_ERROR_MESSAGE,
  TENANT_WIDE_ERROR_MESSAGE,
  useDashboardShares,
} from './useDashboardShares';

vi.mock('@/shared/composables/useI18n', () => import('@/shared/testing/i18n'));

const DASHBOARD_ID = '01DASHBOARD00000000000001';

const SHARE_ID = '01SHARE000000000000000001';

const { toastError } = vi.hoisted(() => ({ toastError: vi.fn() }));

vi.mock('vue-sonner', () => ({
  toast: { error: toastError, success: vi.fn() },
}));

setUrlDefaults({ activeTeam: 'nubos' });

const indexRoute = DashboardSharesController.index({ dashboard: DASHBOARD_ID });

const storeRoute = DashboardSharesController.store({ dashboard: DASHBOARD_ID });

const destroyRoute = DashboardSharesController.destroy({
  dashboard: DASHBOARD_ID,
  share: SHARE_ID,
});

const optionsRoute = DashboardShareOptionsController({
  dashboard: DASHBOARD_ID,
});

const updateRoute = DashboardsController.update({ dashboard: DASHBOARD_ID });

const createdShare = {
  id: SHARE_ID,
  grantee_type: 'user',
  grantee_id: '01USER0000000000000000001',
  can_edit: false,
};

interface ScopedShares {
  shares: ReturnType<typeof useDashboardShares>;
  stop: () => void;
}

function scoped(): ScopedShares {
  const scope = effectScope();
  const shares = scope.run(() => useDashboardShares(DASHBOARD_ID, () => false));

  if (shares === undefined) {
    throw new Error('the scope produced no composable');
  }

  return { shares, stop: () => scope.stop() };
}

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-xsrf-token';
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  vi.clearAllMocks();
});

describe('useDashboardShares — creating a share', () => {
  it('keeps the created share and clears the error on success', async () => {
    const served = serveJson(storeRoute, { data: createdShare }, { status: 201 });

    const { shares, stop } = scoped();

    const created = await shares.create({
      grantee_type: 'user',
      grantee_id: '01USER0000000000000000001',
      can_edit: false,
    });

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('POST');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/dashboards/${DASHBOARD_ID}/shares`);
    expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('test-xsrf-token');
    expect(served.lastRequest()?.body).toEqual({
      grantee_type: 'user',
      grantee_id: '01USER0000000000000000001',
      can_edit: false,
    });
    expect(created?.id).toBe(SHARE_ID);
    expect(shares.shares.value).toHaveLength(1);
    expect(shares.error.value).toBeNull();

    stop();
  });

  it('shows the message the server sent instead of a fixed sentence', async () => {
    serveValidationError(storeRoute, {
      grantee_id: ['Dieser Empfänger hat das Dashboard bereits.'],
    });

    const { shares, stop } = scoped();

    await expect(
      shares.create({
        grantee_type: 'user',
        grantee_id: '01USER0000000000000000001',
        can_edit: false,
      }),
    ).resolves.toBeNull();

    expect(shares.error.value).toBe('Dieser Empfänger hat das Dashboard bereits.');
    expect(toastError).toHaveBeenCalledWith('Dieser Empfänger hat das Dashboard bereits.');

    stop();
  });

  it('keeps the German fallback when the refusal carries no message', async () => {
    serveServerError(storeRoute, {});

    const { shares, stop } = scoped();

    await expect(
      shares.create({
        grantee_type: 'user',
        grantee_id: '01USER0000000000000000001',
        can_edit: false,
      }),
    ).resolves.toBeNull();

    expect(shares.error.value).toBe(t(SHARE_CREATE_ERROR_MESSAGE));
    expect(toastError).toHaveBeenCalledWith(t(SHARE_CREATE_ERROR_MESSAGE));

    stop();
  });

  it('keeps the German fallback when the request never reaches the server', async () => {
    serveNetworkError(storeRoute);

    const { shares, stop } = scoped();

    await expect(
      shares.create({
        grantee_type: 'user',
        grantee_id: '01USER0000000000000000001',
        can_edit: false,
      }),
    ).resolves.toBeNull();

    expect(shares.error.value).toBe(t(SHARE_CREATE_ERROR_MESSAGE));
    expect(shares.shares.value).toEqual([]);

    stop();
  });

  it('clears its own refusal once the next create succeeds', async () => {
    serveJson(storeRoute, { message: 'Empfänger unbekannt.' }, { status: 422, once: true });

    const { shares, stop } = scoped();

    await shares.create({
      grantee_type: 'user',
      grantee_id: '01USER0000000000000000001',
      can_edit: false,
    });

    expect(shares.error.value).toBe('Empfänger unbekannt.');

    serveJson(
      storeRoute,
      {
        data: {
          ...createdShare,
          grantee_id: '01USER0000000000000000002',
        },
      },
      { status: 201 },
    );

    await shares.create({
      grantee_type: 'user',
      grantee_id: '01USER0000000000000000002',
      can_edit: false,
    });

    expect(shares.error.value).toBeNull();

    stop();
  });
});

describe('useDashboardShares — revoking a share', () => {
  it('sends the delete and drops the share from the list', async () => {
    serveJson(storeRoute, { data: createdShare }, { status: 201 });
    const served = serveEmpty(destroyRoute);

    const { shares, stop } = scoped();

    await shares.create({
      grantee_type: 'user',
      grantee_id: '01USER0000000000000000001',
      can_edit: false,
    });

    await expect(shares.revoke(SHARE_ID)).resolves.toBe(true);

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('DELETE');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/dashboards/${DASHBOARD_ID}/shares/${SHARE_ID}`);
    expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('test-xsrf-token');
    expect(shares.shares.value).toEqual([]);

    stop();
  });

  it('shows the message the server sent instead of a fixed sentence', async () => {
    serveJson(destroyRoute, { message: 'Sie dürfen diese Freigabe nicht widerrufen.' }, { status: 403 });

    const { shares, stop } = scoped();

    await expect(shares.revoke(SHARE_ID)).resolves.toBe(false);

    expect(shares.error.value).toBe('Sie dürfen diese Freigabe nicht widerrufen.');

    stop();
  });

  it('keeps the German fallback when the request never reaches the server', async () => {
    serveNetworkError(destroyRoute);

    const { shares, stop } = scoped();

    await expect(shares.revoke(SHARE_ID)).resolves.toBe(false);

    expect(shares.error.value).toBe(t(SHARE_REVOKE_ERROR_MESSAGE));

    stop();
  });
});

describe('useDashboardShares — the tenant-wide switch', () => {
  it('sends the new switch state and keeps it on success', async () => {
    const served = serveJson(updateRoute, {});

    const { shares, stop } = scoped();

    await expect(shares.setTenantWide(true)).resolves.toBe(true);

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('PUT');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/dashboards/${DASHBOARD_ID}`);
    expect(served.lastRequest()?.body).toEqual({ is_tenant_wide: true });
    expect(shares.isTenantWide.value).toBe(true);
    expect(shares.tenantWideError.value).toBeNull();

    stop();
  });

  it('turns the switch on while the server has not answered yet', async () => {
    const served = serveJson(updateRoute, {}, { held: true });

    const { shares, stop } = scoped();

    const switching = shares.setTenantWide(true);
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(shares.isTenantWide.value).toBe(true);

    served.release();
    await switching;

    stop();
  });

  it('puts the switch back and states the reason the server gave', async () => {
    serveValidationError(updateRoute, {
      is_tenant_wide: ['Das Dashboard hat keinen Eigentümer mehr.'],
    });

    const { shares, stop } = scoped();

    await expect(shares.setTenantWide(true)).resolves.toBe(false);
    await flushPromises();

    expect(shares.isTenantWide.value).toBe(false);
    expect(shares.tenantWideError.value).toBe('Das Dashboard hat keinen Eigentümer mehr.');

    stop();
  });

  it('puts the switch back and keeps the German fallback when the refusal carries no message', async () => {
    serveServerError(updateRoute, {});

    const { shares, stop } = scoped();

    await expect(shares.setTenantWide(true)).resolves.toBe(false);
    await flushPromises();

    expect(shares.isTenantWide.value).toBe(false);
    expect(shares.tenantWideError.value).toBe(t(TENANT_WIDE_ERROR_MESSAGE));

    stop();
  });

  it('puts the switch back and keeps the German fallback when the network drops the request', async () => {
    serveNetworkError(updateRoute);

    const { shares, stop } = scoped();

    await expect(shares.setTenantWide(true)).resolves.toBe(false);

    expect(shares.isTenantWide.value).toBe(false);
    expect(shares.tenantWideError.value).toBe(t(TENANT_WIDE_ERROR_MESSAGE));

    stop();
  });
});

describe('useDashboardShares — loading shares and recipients', () => {
  it('keeps the delivered shares and leaves the loading state', async () => {
    const share = { id: SHARE_ID };
    const served = serveJson(indexRoute, { data: [share] });
    const { shares, stop } = scoped();

    await shares.load();

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('GET');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/dashboards/${DASHBOARD_ID}/shares`);
    expect(served.lastRequest()?.headers.get('Accept')).toBe('application/json');
    expect(shares.shares.value).toEqual([share]);
    expect(shares.loading.value).toBe(false);
    expect(shares.error.value).toBeNull();

    stop();
  });

  it('stays loading until the shares arrive', async () => {
    const served = serveJson(indexRoute, { data: [] }, { held: true });
    const { shares, stop } = scoped();

    const loading = shares.load();
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(shares.loading.value).toBe(true);

    served.release();
    await loading;

    expect(shares.loading.value).toBe(false);

    stop();
  });

  it('says the shares could not be loaded and keeps the list it had', async () => {
    serveServerError(indexRoute, { message: 'boom' });
    const { shares, stop } = scoped();

    await shares.load();

    expect(shares.shares.value).toEqual([]);
    expect(shares.loading.value).toBe(false);
    expect(shares.error.value).toBe(t(SHARES_LOAD_ERROR_MESSAGE));
    expect(toastError).toHaveBeenCalledWith(t(SHARES_LOAD_ERROR_MESSAGE));

    stop();
  });

  it('delivers the recipients per grantee type', async () => {
    const served = serveJson(optionsRoute, {
      options: { team: [{ value: 'team-1', label: 'Vertrieb' }] },
    });
    const { shares, stop } = scoped();

    await shares.loadOptions();

    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/dashboards/${DASHBOARD_ID}/share-options`);
    expect(shares.options.value).toEqual({
      user: [],
      team: [{ value: 'team-1', label: 'Vertrieb' }],
      role: [],
    });
    expect(shares.error.value).toBeNull();

    stop();
  });

  it('keeps only the answer of the latest recipient request when an older one arrives late', async () => {
    serveJson(optionsRoute, { options: { user: [{ value: 'new', label: 'Neu' }] } }, { once: true });
    const stale = serveJson(
      optionsRoute,
      { options: { user: [{ value: 'old', label: 'Alt' }] } },
      { once: true, held: true },
    );
    const { shares, stop } = scoped();

    const first = shares.loadOptions();
    await vi.waitFor(() => expect(stale.requests).toHaveLength(1));
    const second = shares.loadOptions();
    await second;

    expect(shares.options.value.user).toEqual([{ value: 'new', label: 'Neu' }]);

    stale.release();
    await first;
    await flushPromises();

    expect(shares.options.value.user).toEqual([{ value: 'new', label: 'Neu' }]);
    expect(shares.options.value.team).toEqual([]);

    stop();
  });

  it('says the recipients could not be loaded on a broken answer', async () => {
    serveJson(optionsRoute, { unexpected: true });
    const { shares, stop } = scoped();

    await shares.loadOptions();

    expect(shares.error.value).toBe(t(OPTIONS_LOAD_ERROR_MESSAGE));

    stop();
  });

  it('says the recipients could not be loaded when the network drops the request', async () => {
    serveNetworkError(optionsRoute);
    const { shares, stop } = scoped();

    await shares.loadOptions();

    expect(shares.error.value).toBe(t(OPTIONS_LOAD_ERROR_MESSAGE));
    expect(toastError).toHaveBeenCalledWith(t(OPTIONS_LOAD_ERROR_MESSAGE));

    stop();
  });

  it('aborts a request still in flight once its scope ends and changes nothing afterwards', async () => {
    const served = serveJson(indexRoute, { data: [{ id: SHARE_ID }] }, { held: true });
    const { shares, stop } = scoped();

    const pending = shares.load();
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));
    stop();

    expect(served.lastRequest()?.signal.aborted).toBe(true);

    served.release();
    await pending;

    expect(shares.shares.value).toEqual([]);
    expect(toastError).not.toHaveBeenCalled();
  });
});
