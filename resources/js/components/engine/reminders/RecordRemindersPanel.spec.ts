import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import RecordRemindersPanel from '@/components/engine/reminders/RecordRemindersPanel.vue';
import { setUrlDefaults } from '@/wayfinder';

setUrlDefaults({ activeTeam: 'nubos' });

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { on: vi.fn(() => vi.fn()), visit: vi.fn(), get: vi.fn() },
    usePage: () => ({
        url: '/nubos/records/01RECORD00000000000000000A',
        props: {
            auth: { user: null, can: {}, authority: null },
            reminderTypeOptions: [],
        },
        scrollProps: {},
    }),
}));

const recordId = '01RECORD00000000000000000A';

const formStub = {
    props: ['open', 'recordId', 'reminder'],
    emits: ['update:open', 'saved'],
    template: '<div data-reminder-form-stub :data-open="open" />',
};

function mountPanel(readonly = false): VueWrapper {
    return mount(RecordRemindersPanel, {
        props: { recordId, readonly },
        global: { stubs: { ReminderForm: formStub } },
    });
}

describe('RecordRemindersPanel', () => {
    it('offers exactly one way in — creating a reminder', () => {
        const wrapper = mountPanel();

        expect(wrapper.find('[data-reminder-new]').exists()).toBe(true);
        expect(wrapper.findAll('[data-reminder-item]')).toHaveLength(0);
    });

    it('opens the form on the create button', async () => {
        const wrapper = mountPanel();

        expect(
            wrapper.get('[data-reminder-form-stub]').attributes('data-open'),
        ).toBe('false');

        await wrapper.get('[data-create-button]').trigger('click');

        expect(
            wrapper.get('[data-reminder-form-stub]').attributes('data-open'),
        ).toBe('true');
    });

    it('tells the page once a reminder was saved so the strand can reload', async () => {
        const wrapper = mountPanel();

        await wrapper.getComponent(formStub).vm.$emit('saved');

        expect(wrapper.emitted('changed')).toHaveLength(1);
    });

    it('offers no create path at all on a deleted record', () => {
        const wrapper = mountPanel(true);

        expect(wrapper.find('[data-reminder-new]').exists()).toBe(false);
        expect(wrapper.find('[data-record-reminders-readonly]').exists()).toBe(
            true,
        );
    });
});
