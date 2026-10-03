import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import RecordNotesPanel from '@/components/engine/notes/RecordNotesPanel.vue';
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

const composerStub = {
    props: ['recordId', 'readonly'],
    emits: ['created'],
    template:
        '<div data-record-note-composer-stub :data-record-id="recordId" :data-readonly="readonly" />',
};

function mountPanel(readonly = false): VueWrapper {
    return mount(RecordNotesPanel, {
        props: { recordId, readonly },
        global: { stubs: { RecordNoteComposer: composerStub } },
    });
}

describe('RecordNotesPanel', () => {
    it('carries the composer and hands it the record', () => {
        expect(
            mountPanel()
                .get('[data-record-note-composer-stub]')
                .attributes('data-record-id'),
        ).toBe(recordId);
    });

    it('hands its own read-only state down to the composer', () => {
        expect(
            mountPanel(true)
                .get('[data-record-note-composer-stub]')
                .attributes('data-readonly'),
        ).toBe('true');
    });

    it('lists no note of its own — the strand carries them', () => {
        const wrapper = mountPanel();

        expect(wrapper.findAll('[data-record-note-item]')).toHaveLength(0);
        expect(wrapper.find('[data-record-notes-empty]').exists()).toBe(false);
    });

    it('tells the page once a note was written so the strand can reload', async () => {
        const wrapper = mountPanel();

        await wrapper.getComponent(composerStub).vm.$emit('created');

        expect(wrapper.emitted('changed')).toHaveLength(1);
    });

    it('explains why nothing can be written on a deleted record', () => {
        expect(
            mountPanel(true).find('[data-record-notes-readonly]').exists(),
        ).toBe(true);
    });
});
