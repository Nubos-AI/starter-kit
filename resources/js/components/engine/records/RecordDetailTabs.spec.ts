import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import RecordDetailTabs from '@/components/engine/records/RecordDetailTabs.vue';
import { RECORD_DETAIL_TABS } from '@/lib/recordDetailTabs';
import { setUrlDefaults } from '@/wayfinder';

setUrlDefaults({ activeTeam: 'nubos' });

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { on: vi.fn(() => vi.fn()), visit: vi.fn(), get: vi.fn() },
    usePage: () => ({
        url: '/nubos/records/01RECORD00000000000000000A',
        props: { auth: { user: null, can: {}, authority: null } },
        scrollProps: {},
    }),
}));

const recordId = '01RECORD00000000000000000A';

const panelStubs = {
    RecordActivitiesPanel: {
        props: ['recordId', 'readonly'],
        template:
            '<div data-activities-panel-stub :data-readonly="readonly" />',
    },
    RecordFilesPanel: {
        props: ['recordId', 'readonly'],
        template: '<div data-files-panel-stub :data-readonly="readonly" />',
    },
    RecordNotesPanel: {
        props: ['recordId', 'readonly'],
        template: '<div data-notes-panel-stub :data-readonly="readonly" />',
    },
    RecordRemindersPanel: {
        props: ['recordId', 'readonly'],
        template: '<div data-reminders-panel-stub :data-readonly="readonly" />',
    },
    RecordDocumentsPanel: {
        props: ['recordId', 'readonly'],
        template: '<div data-documents-panel-stub :data-readonly="readonly" />',
    },
};

function mountTabs(readonly = false): VueWrapper {
    return mount(RecordDetailTabs, {
        props: { recordId, readonly },
        global: { stubs: panelStubs },
    });
}

async function open(wrapper: VueWrapper, tab: string): Promise<void> {
    await wrapper
        .get(`[data-record-detail-tab="${tab}"]`)
        .trigger('mousedown', { button: 0 });
}

describe('RecordDetailTabs', () => {
    it('offers the core tabs in the agreed order', () => {
        const labels = mountTabs()
            .findAll('[data-record-detail-tab]')
            .map((tab) => tab.text());

        expect(labels).toEqual([
            'Notizen',
            'Aktivität',
            'Erinnerungen',
            'Dateien',
        ]);
        expect(labels).toHaveLength(RECORD_DETAIL_TABS.length);
    });

    it('starts on the notes tab', () => {
        expect(mountTabs().find('[data-notes-panel-stub]').exists()).toBe(true);
    });

    it('swaps the panel when another tab is opened', async () => {
        const wrapper = mountTabs();

        await open(wrapper, 'reminders');

        expect(wrapper.find('[data-reminders-panel-stub]').exists()).toBe(true);
        expect(wrapper.find('[data-notes-panel-stub]').exists()).toBe(false);
    });

    it('carries no automations tab — running them belongs to a trigger', () => {
        expect(
            mountTabs().find('[data-record-detail-tab="automations"]').exists(),
        ).toBe(false);
    });

    it('opens working activity and file panels', async () => {
        const wrapper = mountTabs();

        await open(wrapper, 'activity');
        expect(wrapper.find('[data-activities-panel-stub]').exists()).toBe(
            true,
        );

        await open(wrapper, 'files');
        expect(wrapper.find('[data-files-panel-stub]').exists()).toBe(true);
    });

    it('hands its own read-only state down to every writing panel', async () => {
        const wrapper = mountTabs(true);

        expect(
            wrapper.get('[data-notes-panel-stub]').attributes('data-readonly'),
        ).toBe('true');

        await open(wrapper, 'files');
        expect(
            wrapper.get('[data-files-panel-stub]').attributes('data-readonly'),
        ).toBe('true');

        await open(wrapper, 'activity');
        expect(
            wrapper
                .get('[data-activities-panel-stub]')
                .attributes('data-readonly'),
        ).toBe('true');

        await open(wrapper, 'reminders');
        expect(
            wrapper
                .get('[data-reminders-panel-stub]')
                .attributes('data-readonly'),
        ).toBe('true');
    });

    it('passes a change from a panel on to the page', async () => {
        const wrapper = mountTabs();

        await wrapper
            .getComponent(panelStubs.RecordNotesPanel)
            .vm.$emit('changed');

        expect(wrapper.emitted('changed')).toHaveLength(1);
    });
});
