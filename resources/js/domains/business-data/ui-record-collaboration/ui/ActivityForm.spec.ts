import type * as Inertia from '@inertiajs/vue3';
import { fireEvent, render, screen } from '@testing-library/vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import RecordActivitiesController from '@/actions/App/Http/Controllers/Engine/RecordActivitiesController';
import { serveJson, serveNetworkError, serveServerError, serveValidationError } from '@/shared/testing/msw';
import { selectStubs } from '@/shared/testing/selectStubs';
import { setUrlDefaults } from '@/wayfinder';
import type { RecordActivityItem } from '../../data/types/activities';
import ActivityForm from './ActivityForm.vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
  ...(await importOriginal<typeof Inertia>()),
  usePage: () => ({ props: { auth: { user: { id: 'user-1' } } } }),
}));

const formStubs = {
  ...selectStubs,
  SheetTitle: { template: '<h2><slot /></h2>' },
  SheetDescription: { template: '<p><slot /></p>' },
};
const props = {
  recordId: 'record-1',
  activity: null,
  types: [{ id: 'type-1', name: 'Telefonat' }],
  assignees: [{ id: 'user-1', name: 'Anna' }],
};
const existingActivity: RecordActivityItem = {
  id: 'activity-1',
  subject: 'Besprechung',
  occurredAt: '2026-09-19T08:00:00Z',
  result: 'Ergebnis',
  activityTypeId: 'old-type',
  typeName: 'Alter Typ',
  assigneeId: 'user-1',
  assigneeName: 'Anna',
  recordId: 'record-1',
  sourceType: null,
  sourceId: null,
  links: [],
};
const SAVE_FAILED = 'Die Aktivität konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.';

function storeRoute() {
  return RecordActivitiesController.store({ record: 'record-1' });
}

function renderForm(
  overrides: {
    types?: typeof props.types;
    activity?: RecordActivityItem | null;
    initialSubject?: string;
  } = {},
) {
  return render(ActivityForm, {
    props: { ...props, ...overrides },
    global: { stubs: formStubs },
  });
}

function subjectInput(): HTMLInputElement {
  return screen.getByLabelText<HTMLInputElement>('Titel');
}

async function submit(): Promise<void> {
  await fireEvent.submit(document.querySelector<HTMLFormElement>('[data-activity-form]')!);
}

beforeEach(() => {
  setUrlDefaults({ activeTeam: 'nubos' });
  document.cookie = 'XSRF-TOKEN=activity-token';
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

describe('ActivityForm', () => {
  it('prefills the subject of a new activity from the given subject', () => {
    renderForm({ initialSubject: 'Angebot' });

    expect(subjectInput().value).toBe('Angebot');
  });

  it('saves a new activity through the record endpoint and emits success', async () => {
    const served = serveJson(storeRoute(), {});
    const { emitted } = renderForm();

    await fireEvent.update(subjectInput(), 'Rückruf');
    await fireEvent.update(screen.getByLabelText('Ergebnis / Zusammenfassung'), 'Termin vereinbart');
    await fireEvent.update(screen.getByLabelText('Zeitpunkt'), '2026-09-19T10:00');
    await submit();

    await vi.waitFor(() => expect(emitted('saved')).toHaveLength(1));
    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('POST');
    expect(served.lastRequest()?.url.pathname).toBe('/nubos/engine/records/record-1/activities');
    expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('activity-token');
    expect(served.lastRequest()?.body).toEqual({
      subject: 'Rückruf',
      activity_type_id: 'type-1',
      occurred_at: new Date('2026-09-19T10:00').toISOString(),
      assignee_id: 'user-1',
      result: 'Termin vereinbart',
    });
  });

  it('disables saving while the request is pending', async () => {
    const served = serveJson(storeRoute(), {}, { held: true });
    const { emitted } = renderForm();

    await fireEvent.update(subjectInput(), 'Rückruf');
    await submit();
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    const pending = await screen.findByRole('button', {
      name: 'Wird gespeichert …',
    });
    expect(pending.hasAttribute('disabled')).toBe(true);
    expect(screen.getByRole('button', { name: 'Abbrechen' }).hasAttribute('disabled')).toBe(true);
    expect(emitted('saved')).toBeUndefined();

    served.release();

    await vi.waitFor(() => expect(emitted('saved')).toHaveLength(1));
    expect(screen.getByRole('button', { name: 'Speichern' }).hasAttribute('disabled')).toBe(false);
  });

  it('shows field errors of a rejected save and stays open', async () => {
    serveValidationError(storeRoute(), {
      subject: ['Der Titel ist zu lang.'],
    });
    const { emitted } = renderForm();

    await fireEvent.update(subjectInput(), 'Rückruf');
    await submit();

    expect(await screen.findByText('Der Titel ist zu lang.')).toBeTruthy();
    expect(subjectInput().getAttribute('aria-invalid')).toBe('true');
    expect(subjectInput().value).toBe('Rückruf');
    expect(screen.queryByRole('alert')).toBeNull();
    expect(emitted('saved')).toBeUndefined();
  });

  it('keeps the form and user input when the server fails', async () => {
    serveServerError(storeRoute());
    const { emitted } = renderForm();

    await fireEvent.update(subjectInput(), 'Rückruf');
    await submit();

    expect((await screen.findByRole('alert')).textContent).toContain(SAVE_FAILED);
    expect(subjectInput().value).toBe('Rückruf');
    expect(emitted('saved')).toBeUndefined();
  });

  it('keeps the form and user input when the request cannot be sent', async () => {
    serveNetworkError(storeRoute());
    const { emitted } = renderForm();

    await fireEvent.update(subjectInput(), 'Rückruf');
    await submit();

    expect((await screen.findByRole('alert')).textContent).toContain(SAVE_FAILED);
    expect(subjectInput().value).toBe('Rückruf');
    expect(emitted('saved')).toBeUndefined();
  });

  it('updates an existing activity and preserves its retired type', async () => {
    const served = serveJson(
      RecordActivitiesController.update({
        record: 'record-1',
        activity: 'activity-1',
      }),
      {},
    );
    const { emitted } = renderForm({
      types: [],
      activity: existingActivity,
    });

    expect(screen.getByText(/Alter Typ\s+\(gelöscht\)/)).toBeTruthy();

    await submit();

    await vi.waitFor(() => expect(emitted('saved')).toHaveLength(1));
    expect(served.lastRequest()?.method).toBe('PUT');
    expect(served.lastRequest()?.url.pathname).toBe('/nubos/engine/records/record-1/activities/activity-1');
    expect(served.lastRequest()?.body).toMatchObject({
      subject: 'Besprechung',
      activity_type_id: 'old-type',
      occurred_at: '2026-09-19T08:00:00.000Z',
      assignee_id: 'user-1',
      result: 'Ergebnis',
    });
  });
});
