import { fireEvent, render, screen, within } from '@testing-library/vue';
import { DialogContent, DialogPortal } from 'reka-ui';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import RecordActivitiesController from '@/actions/App/Http/Controllers/Engine/RecordActivitiesController';
import { serveEmpty, serveJson, serveServerError, serveValidationError } from '@/shared/testing/msw';
import { SheetDescription, SheetTitle } from '@/shared/ui/primitives/sheet';
import { setUrlDefaults } from '@/wayfinder';
import type { ActivityListResponse, RecordActivityItem } from '../../data/types/activities';
import RecordActivitiesPanel from './RecordActivitiesPanel.vue';

vi.mock('@/shared/composables/usePermissions', () => ({
  usePermissions: () => ({ can: () => true }),
}));

const formStub = {
  props: ['activity'],
  emits: ['cancel', 'saved'],
  components: { SheetTitle, SheetDescription },
  template: `<form data-activity-form-stub :data-activity-id="activity?.id ?? ''"><SheetTitle>{{ activity ? 'Aktivität bearbeiten' : 'Neue Aktivität' }}</SheetTitle><SheetDescription>Aktivität erfassen</SheetDescription><button type="button" @click="$emit('cancel')">Formular abbrechen</button><button type="button" @click="$emit('saved')">Formular speichern</button></form>`,
};
const sheetContentProbe = {
  props: ['side'],
  components: { DialogContent, DialogPortal },
  template: '<DialogPortal><DialogContent :data-sheet-side="side"><slot /></DialogContent></DialogPortal>',
};

const activity: RecordActivityItem = {
  id: 'activity-1',
  recordId: 'record-1',
  sourceType: null,
  sourceId: null,
  subject: 'Telefonat',
  occurredAt: '2026-09-19T08:00:00Z',
  result: '',
  activityTypeId: null,
  assigneeId: null,
  typeName: 'Anruf',
  assigneeName: 'Anna',
  links: [],
};

function listResponse(overrides: Partial<ActivityListResponse> = {}): ActivityListResponse {
  return {
    data: [activity],
    activityTypes: [{ id: 'type-1', name: 'Anruf' }],
    assignees: [{ id: 'user-1', name: 'Anna' }],
    canManage: true,
    meta: { current_page: 1, last_page: 1 },
    ...overrides,
  } as ActivityListResponse;
}

const LOAD_FAILED = 'Die Aktivitäten konnten nicht geladen werden. Bitte versuchen Sie es erneut.';
const DELETE_FAILED = 'Die Aktivität konnte nicht gelöscht werden. Bitte versuchen Sie es erneut.';

function indexRoute() {
  return RecordActivitiesController.index({ record: 'record-1' });
}

function destroyRoute() {
  return RecordActivitiesController.destroy({
    record: 'record-1',
    activity: 'activity-1',
  });
}

function renderPanel() {
  return render(RecordActivitiesPanel, {
    props: { recordId: 'record-1' },
    global: {
      stubs: { ActivityForm: formStub, SheetContent: sheetContentProbe },
    },
  });
}

function panelElement(): HTMLElement {
  return document.querySelector<HTMLElement>('[data-record-activities-panel]')!;
}

async function confirmDeletion(): Promise<HTMLElement> {
  await fireEvent.click(await within(panelElement()).findByRole('button', { name: 'Löschen' }));

  const dialog = await screen.findByRole('dialog');
  await fireEvent.click(within(dialog).getByRole('button', { name: 'Löschen' }));

  return dialog;
}

beforeEach(() => {
  setUrlDefaults({ activeTeam: 'nubos' });
  document.cookie = 'XSRF-TOKEN=panel-token';
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

describe('RecordActivitiesPanel loading', () => {
  it('requests the first page of the record activities', async () => {
    const served = serveJson(indexRoute(), listResponse());

    renderPanel();

    expect(await screen.findByText('Telefonat')).toBeTruthy();
    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('GET');
    expect(served.lastRequest()?.url.pathname).toBe('/nubos/engine/records/record-1/activities');
    expect(served.lastRequest()?.url.searchParams.get('page')).toBe('1');
  });

  it('shows a skeleton while the first page is pending', async () => {
    const served = serveJson(indexRoute(), listResponse(), {
      held: true,
    });

    renderPanel();
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(await screen.findByLabelText('Aktivitäten werden geladen')).toBeTruthy();
    expect(screen.queryByText('Telefonat')).toBeNull();

    served.release();

    expect(await screen.findByText('Telefonat')).toBeTruthy();
    expect(screen.queryByLabelText('Aktivitäten werden geladen')).toBeNull();
  });

  it('explains a failed load and retries on request', async () => {
    const failed = serveServerError(indexRoute(), undefined, {
      once: true,
    });

    renderPanel();

    expect((await screen.findByRole('alert')).textContent).toContain(LOAD_FAILED);
    expect(failed.requests).toHaveLength(1);

    const retried = serveJson(indexRoute(), listResponse());
    await fireEvent.click(screen.getByRole('button', { name: 'Erneut versuchen' }));

    expect(await screen.findByText('Telefonat')).toBeTruthy();
    expect(retried.requests).toHaveLength(1);
    expect(screen.queryByRole('alert')).toBeNull();
  });
});

describe('RecordActivitiesPanel side panel', () => {
  it('opens the create form in a right sheet and closes on cancel', async () => {
    serveJson(indexRoute(), listResponse({ data: [], assignees: [] }));

    renderPanel();

    const create = await screen.findByRole('button', {
      name: 'Neue Aktivität',
    });
    expect(document.querySelector('[data-activity-form-stub]')).toBeNull();
    expect(document.querySelector('[data-activity-new] [data-create-button]')?.textContent).toContain('Neue Aktivität');
    expect(document.querySelector('[data-activity-new] svg')).not.toBeNull();

    await fireEvent.click(create);

    const dialog = await screen.findByRole('dialog');
    expect(dialog.getAttribute('data-sheet-side')).toBe('right');
    expect(dialog.querySelector('[data-activity-form-stub]')).not.toBeNull();
    expect(panelElement().querySelector('[data-activity-form-stub]')).toBeNull();

    await fireEvent.click(within(dialog).getByRole('button', { name: 'Formular abbrechen' }));

    await vi.waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
  });

  it('opens an existing activity in the same sheet, closes after saving and reloads', async () => {
    const served = serveJson(indexRoute(), listResponse());
    const { emitted } = renderPanel();

    await fireEvent.click(await screen.findByRole('button', { name: 'Bearbeiten' }));

    const dialog = await screen.findByRole('dialog');
    expect(dialog.getAttribute('data-sheet-side')).toBe('right');
    expect(dialog.querySelector('[data-activity-form-stub]')?.getAttribute('data-activity-id')).toBe('activity-1');

    await fireEvent.click(within(dialog).getByRole('button', { name: 'Formular speichern' }));

    await vi.waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(emitted('changed')).toHaveLength(1);
    await vi.waitFor(() => expect(served.requests).toHaveLength(2));
  });
});

describe('RecordActivitiesPanel deletion', () => {
  it('deletes a confirmed activity and reloads the list', async () => {
    const listed = serveJson(indexRoute(), listResponse());
    const removed = serveEmpty(destroyRoute());
    const { emitted } = renderPanel();

    await confirmDeletion();

    await vi.waitFor(() => expect(emitted('changed')).toHaveLength(1));
    expect(removed.requests).toHaveLength(1);
    expect(removed.lastRequest()?.method).toBe('DELETE');
    expect(removed.lastRequest()?.url.pathname).toBe('/nubos/engine/records/record-1/activities/activity-1');
    expect(removed.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('panel-token');
    await vi.waitFor(() => expect(listed.requests).toHaveLength(2));
    await vi.waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
  });

  it('keeps the confirmation open and explains a failed deletion', async () => {
    const listed = serveJson(indexRoute(), listResponse());
    serveServerError(destroyRoute());
    const { emitted } = renderPanel();

    const dialog = await confirmDeletion();

    expect((await within(dialog).findByRole('alert')).textContent).toContain(DELETE_FAILED);
    expect(emitted('changed')).toBeUndefined();
    expect(listed.requests).toHaveLength(1);
  });

  it('treats a rejected deletion as a failure instead of a success', async () => {
    const listed = serveJson(indexRoute(), listResponse());
    serveValidationError(destroyRoute(), {
      activity: ['Die Aktivität ist gesperrt.'],
    });
    const { emitted } = renderPanel();

    const dialog = await confirmDeletion();

    expect((await within(dialog).findByRole('alert')).textContent).toContain(DELETE_FAILED);
    expect(emitted('changed')).toBeUndefined();
    expect(listed.requests).toHaveLength(1);
  });
});

describe('RecordActivitiesPanel quick links', () => {
  it('links an activity to its source but not back to the record it sits on', async () => {
    serveJson(
      indexRoute(),
      listResponse({
        data: [
          {
            ...activity,
            links: [
              {
                key: 'record',
                id: 'record-1',
                label: 'C-1',
                href: '/nubos/records/record-1',
              },
              {
                key: 'email_message',
                id: 'mail-1',
                label: 'E-Mail: Angebot',
                href: '/nubos/mails/mail-1',
              },
            ],
          },
          {
            ...activity,
            id: 'activity-2',
            subject: 'Ohne Herkunft',
            links: [],
          },
        ],
        activityTypes: [],
        assignees: [],
        canManage: false,
      }),
    );

    renderPanel();
    await screen.findByText('Ohne Herkunft');

    const items = document.querySelectorAll('[data-activity-item]');
    const links = items[0].querySelectorAll('[data-source-link]');

    expect(links).toHaveLength(1);
    expect(links[0].getAttribute('data-source-link')).toBe('email_message');
    expect(links[0].getAttribute('href')).toBe('/nubos/mails/mail-1');
    expect(links[0].textContent?.trim()).toBe('E-Mail: Angebot');
    expect(items[1].querySelector('[data-source-link]')).toBeNull();
  });
});
