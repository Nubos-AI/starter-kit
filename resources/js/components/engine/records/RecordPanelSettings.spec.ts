import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import RecordPanelSettings from '@/components/engine/records/RecordPanelSettings.vue';
import {
    hydrateObjectTypePreferences,
    hydratePreferences,
} from '@/composables/useUserPreferences';
import { RELATIONS_PANEL_ID } from '@/lib/recordPanels';
import type { RecordPanel } from '@/lib/recordPanels';
import { emptyObjectTypePreferences } from '@/types/preferences';
import type { PreferenceDocument } from '@/types/preferences';
import { setUrlDefaults } from '@/wayfinder';

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
    template:
        '<button type="button" :data-checked="modelValue" @click="$emit(\'update:modelValue\', !modelValue)" />',
};

const stubs = {
    Switch: SwitchStub,
    Sheet: {
        name: 'Sheet',
        props: ['open'],
        emits: ['update:open'],
        template: '<div><slot /></div>',
    },
    SheetContent: passthrough,
    SheetHeader: passthrough,
    SheetTitle: { template: '<h2><slot /></h2>' },
    SheetDescription: { template: '<p><slot /></p>' },
};

function emptyDocument(): PreferenceDocument {
    return {
        settings: {
            appearance: 'system',
            density: 'compact',
            pageSize: 100,
            sidebarOpen: true,
            startObjectTypeId: null,
        },
        objectTypes: {},
        grids: {},
        policy: {
            columnsAndSorting: { records: true, configuration: true },
            viewMode: { records: true },
            filterAndSegment: { records: false },
            layoutAndAppearance: { global: true },
            panelState: { records: true },
            panelVisibility: { records: true },
        },
    };
}

function mountSheet(
    props: Record<string, unknown> = {},
): ReturnType<typeof mount> {
    return mount(RecordPanelSettings, {
        props: {
            open: true,
            panels,
            objectTypeId: OBJECT_TYPE_ID,
            ...props,
        },
        global: { stubs },
    });
}

beforeEach(() => {
    vi.stubGlobal(
        'fetch',
        vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve(emptyDocument()),
            } as unknown as Response),
        ),
    );
    hydratePreferences(emptyDocument());
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('RecordPanelSettings', () => {
    it('lists every panel with its switch turned on by default', () => {
        const wrapper = mountSheet();

        expect(
            wrapper
                .findAll('[data-panel-switch]')
                .map((entry) => entry.attributes('data-panel-switch')),
        ).toEqual(panels.map((panel) => panel.id));

        expect(
            wrapper
                .findAll('[data-panel-switch]')
                .every((entry) => entry.attributes('data-checked') === 'true'),
        ).toBe(true);
    });

    it('reflects the panels the user already switched off', () => {
        hydrateObjectTypePreferences(OBJECT_TYPE_ID, {
            ...emptyObjectTypePreferences(),
            hiddenSections: [RELATIONS_PANEL_ID],
        });

        const wrapper = mountSheet();

        expect(
            wrapper
                .get(`[data-panel-switch="${RELATIONS_PANEL_ID}"]`)
                .attributes('data-checked'),
        ).toBe('false');
    });

    it('hides a panel when its switch is turned off', async () => {
        const wrapper = mountSheet();

        await wrapper
            .get(`[data-panel-switch="${DATA_PANEL_ID}"]`)
            .trigger('click');

        expect(
            wrapper
                .get(`[data-panel-switch="${DATA_PANEL_ID}"]`)
                .attributes('data-checked'),
        ).toBe('false');
    });

    it('closes on request', () => {
        const wrapper = mountSheet();

        wrapper.getComponent({ name: 'Sheet' }).vm.$emit('update:open', false);

        expect(wrapper.emitted('close')).toHaveLength(1);
    });
});
