import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import type { Ref } from 'vue';
import type { ReminderItem } from '@/composables/useReminders';
import MyReminders from '@/pages/reminders/MyReminders.vue';
import { comboboxStubs } from '@/tests/selectStubs';

const { reminders } = vi.hoisted(() => ({
    reminders: {
        items: null as unknown as Ref<ReminderItem[]>,
        loadMyOpen: vi.fn(),
        complete: vi.fn(),
        destroy: vi.fn(),
        bulkDestroy: vi.fn(),
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    router: { on: vi.fn(() => vi.fn()), visit: vi.fn() },
    usePage: () => ({
        props: {
            auth: { user: null, can: {} },
            reminderTypeOptions: [
                { value: 'type-call', label: 'Anruf' },
                { value: 'type-mail', label: 'E-Mail' },
            ],
        },
    }),
}));

vi.mock('@/composables/useReminders', () => ({
    useReminders: () => ({
        items: reminders.items,
        loading: ref(false),
        error: ref(null),
        loadMyOpen: reminders.loadMyOpen,
        complete: reminders.complete,
        destroy: reminders.destroy,
        bulkDestroy: reminders.bulkDestroy,
    }),
}));

function reminder(id: string, typeId: string): ReminderItem {
    return {
        id,
        subject: `Reminder ${id}`,
        note: null,
        type: { id: typeId, label: typeId },
        dueAt: null,
        doneAt: null,
        owner: null,
        assignee: null,
        record: null,
        links: [],
    };
}

const ReminderGridStub = {
    name: 'ReminderGrid',
    props: ['items'],
    emits: ['edit', 'complete', 'delete', 'bulkDelete'],
    template:
        '<div data-reminder-grid :data-ids="items.map((item) => item.id).join(\',\')" />',
};

function mountPage() {
    return mount(MyReminders, {
        global: {
            stubs: {
                ...comboboxStubs,
                ReminderGrid: ReminderGridStub,
                ReminderForm: true,
                UiExtensionPoint: true,
            },
        },
    });
}

describe('reminders/MyReminders', () => {
    beforeEach(() => {
        reminders.items = ref<ReminderItem[]>([
            reminder('r1', 'type-call'),
            reminder('r2', 'type-mail'),
        ]);
        reminders.loadMyOpen.mockReset().mockResolvedValue(undefined);
        reminders.destroy.mockReset().mockResolvedValue(true);
    });

    it('offers the type filter as a combobox that starts with all types', () => {
        const wrapper = mountPage();
        const options = wrapper
            .get('[data-reminder-type-filter]')
            .findAll('option')
            .map((option) => option.text());

        expect(options).toEqual(['Alle Typen', 'Anruf', 'E-Mail']);
    });

    it('narrows the grid to the chosen reminder type', async () => {
        const wrapper = mountPage();

        await wrapper.get('[data-reminder-type-filter]').setValue('type-mail');

        expect(wrapper.get('[data-reminder-grid]').attributes('data-ids')).toBe(
            'r2',
        );
    });

    it('deletes the reminder the grid confirmed and reloads the list', async () => {
        const wrapper = mountPage();
        await flushPromises();
        reminders.loadMyOpen.mockClear();

        wrapper
            .findComponent({ name: 'ReminderGrid' })
            .vm.$emit('delete', 'r1');
        await flushPromises();

        expect(reminders.destroy).toHaveBeenCalledWith('r1');
        expect(reminders.loadMyOpen).toHaveBeenCalledTimes(1);
    });
});
