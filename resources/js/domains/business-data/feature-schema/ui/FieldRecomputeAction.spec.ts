import { fireEvent, render, screen } from '@testing-library/vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { store as recomputeField } from '@/actions/App/Http/Controllers/Engine/FieldRecomputesController';
import { serveEmpty, serveJson, serveNetworkError, serveServerError } from '@/shared/testing/msw';
import { setUrlDefaults } from '@/wayfinder';
import FieldRecomputeAction from './FieldRecomputeAction.vue';

const START_FAILED = 'Die Neuberechnung ließ sich nicht starten.';

function renderAction() {
  return render(FieldRecomputeAction, {
    props: { objectTypeSlug: 'companies', fieldId: 'fd-brutto' },
  });
}

function recomputeRoute() {
  return recomputeField({ objectType: 'companies', field: 'fd-brutto' });
}

async function startRecompute(): Promise<void> {
  await fireEvent.click(screen.getByRole('button', { name: 'Neu berechnen' }));
}

beforeEach(() => {
  setUrlDefaults({ activeTeam: 'nubos' });
  document.cookie = 'XSRF-TOKEN=recompute-token';
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

describe('FieldRecomputeAction', () => {
  it('posts to the recompute endpoint and reports the record count', async () => {
    const served = serveJson(recomputeRoute(), {
      run: { totalCount: 750000 },
    });

    renderAction();
    await startRecompute();

    expect((await screen.findByRole('status')).textContent).toContain('750.000');
    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('POST');
    expect(served.lastRequest()?.url.pathname).toBe(recomputeRoute().url);
    expect(served.lastRequest()?.headers.get('Accept')).toBe('application/json');
    expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('recompute-token');
  });

  it('reports a running recalculation when the server returns no run', async () => {
    serveJson(recomputeRoute(), { run: null });

    renderAction();
    await startRecompute();

    expect((await screen.findByRole('status')).textContent).toContain('Die Neuberechnung läuft.');
  });

  it('disables the trigger while the start request is pending', async () => {
    const served = serveJson(recomputeRoute(), { run: { totalCount: 3 } }, { held: true });

    renderAction();
    await startRecompute();
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    const trigger = screen.getByRole('button', { name: 'Neu berechnen' });
    expect(trigger.hasAttribute('disabled')).toBe(true);

    await fireEvent.click(trigger);
    served.release();

    await screen.findByRole('status');
    expect(trigger.hasAttribute('disabled')).toBe(false);
    expect(served.requests).toHaveLength(1);
  });

  it('reports a failed start instead of pretending it ran', async () => {
    serveServerError(recomputeRoute());

    renderAction();
    await startRecompute();

    expect(await screen.findByText(START_FAILED)).toBeTruthy();
    expect(screen.queryByRole('status')).toBeNull();
  });

  it('reports a failed start when the request is refused', async () => {
    serveEmpty(recomputeRoute(), { status: 403 });

    renderAction();
    await startRecompute();

    expect(await screen.findByText(START_FAILED)).toBeTruthy();
    expect(screen.queryByRole('status')).toBeNull();
  });

  it('reports a failed start when the request itself fails', async () => {
    serveNetworkError(recomputeRoute());

    renderAction();
    await startRecompute();

    expect(await screen.findByText(START_FAILED)).toBeTruthy();
    expect(screen.queryByRole('status')).toBeNull();
  });
});
