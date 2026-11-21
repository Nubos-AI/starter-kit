import { fireEvent, render, screen } from '@testing-library/vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import UserPreferencesController from '@/actions/App/Http/Controllers/Preferences/UserPreferencesController';
import { hydrateObjectTypePreferences, hydratePreferences } from '@/shared/composables/useUserPreferences';
import { serveJson } from '@/shared/testing/msw';
import { emptyPreferenceDocument } from '@/shared/testing/preferences';
import { emptyObjectTypePreferences } from '@/shared/types/preferences';
import { setUrlDefaults } from '@/wayfinder';
import type { RecordPanel } from '../utils/recordPanels';
import { RELATIONS_PANEL_ID } from '../utils/recordPanels';
import RecordPanelSettings from './RecordPanelSettings.vue';

setUrlDefaults({ activeTeam: 'nubos' });

const OBJECT_TYPE_ID = '01JD9K2M4P7QR8XKAV0T3ZC5NE';

const DATA_PANEL_ID = 'group:ungrouped';

const panels: RecordPanel[] = [
  { id: RELATIONS_PANEL_ID, label: 'Beziehungen' },
  { id: DATA_PANEL_ID, label: 'Daten' },
  { id: 'group:fg-address', label: 'Adresse' },
];

const passthrough = { template: '<div><slot /></div>' };

const SwitchStub = {
  props: ['modelValue', 'disabled'],
  emits: ['update:modelValue'],
  template: '<button type="button" :data-checked="modelValue" @click="$emit(\'update:modelValue\', !modelValue)" />',
};

const stubs = {
  Switch: SwitchStub,
  Sheet: {
    props: ['open'],
    emits: ['update:open'],
    template: '<div><button type="button" data-sheet-dismiss @click="$emit(\'update:open\', false)" /><slot /></div>',
  },
  SheetContent: passthrough,
  SheetHeader: passthrough,
  SheetTitle: { template: '<h2><slot /></h2>' },
  SheetDescription: { template: '<p><slot /></p>' },
};

function renderSheet(props: Record<string, unknown> = {}) {
  return render(RecordPanelSettings, {
    props: {
      open: true,
      panels,
      objectTypeId: OBJECT_TYPE_ID,
      ...props,
    },
    global: { stubs },
  });
}

function switchFor(panelId: string): HTMLElement {
  return document.querySelector<HTMLElement>(`[data-panel-switch="${panelId}"]`)!;
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
  hydratePreferences(emptyPreferenceDocument());
});

afterEach(() => {
  vi.useRealTimers();
});

describe('RecordPanelSettings', () => {
  it('lists every panel with its switch turned on by default', () => {
    renderSheet();

    const switches = Array.from(document.querySelectorAll('[data-panel-switch]'));

    expect(switches.map((entry) => entry.getAttribute('data-panel-switch'))).toEqual(panels.map((panel) => panel.id));
    expect(switches.every((entry) => entry.getAttribute('data-checked') === 'true')).toBe(true);
    expect(screen.getByText('Beziehungen')).toBeTruthy();
  });

  it('reflects the panels the user already switched off', () => {
    hydrateObjectTypePreferences(OBJECT_TYPE_ID, {
      ...emptyObjectTypePreferences(),
      hiddenSections: [RELATIONS_PANEL_ID],
    });

    renderSheet();

    expect(switchFor(RELATIONS_PANEL_ID).getAttribute('data-checked')).toBe('false');
  });

  it('hides a panel when its switch is turned off and persists the choice', async () => {
    const served = serveJson(UserPreferencesController.update(), emptyPreferenceDocument());

    renderSheet();

    await fireEvent.click(switchFor(DATA_PANEL_ID));

    expect(switchFor(DATA_PANEL_ID).getAttribute('data-checked')).toBe('false');
    expect(served.requests).toHaveLength(0);

    await vi.advanceTimersByTimeAsync(500);
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(served.lastRequest()?.method).toBe('PUT');
    expect(served.lastRequest()?.url.pathname).toBe('/nubos/engine/preferences');
    expect(served.lastRequest()?.headers.get('Content-Type')).toBe('application/json');
    expect(JSON.stringify(served.lastRequest()?.body)).toContain(DATA_PANEL_ID);
  });

  it('closes on request', async () => {
    const { emitted } = renderSheet();

    await fireEvent.click(document.querySelector<HTMLElement>('[data-sheet-dismiss]')!);

    expect(emitted('close')).toHaveLength(1);
  });
});
