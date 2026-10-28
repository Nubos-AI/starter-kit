import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ReminderForm from '@/components/engine/reminders/ReminderForm.vue';
import type { ReminderItem } from '@/composables/useReminders';

const { create, update } = vi.hoisted(() => ({
    create: vi.fn(),
    update: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { reminderTypeOptions: [] } }),
}));

vi.mock('@/composables/useReminders', () => ({
    useReminders: () => ({ create, update }),
}));

function mountForm(reminder: ReminderItem | null = null) {
    return mount(ReminderForm, {
        props: { open: true, recordId: 'record-1', reminder },
        global: {
            stubs: {
                Sheet: { template: '<div><slot /></div>' },
                SheetContent: { template: '<div><slot /></div>' },
                SheetTitle: { template: '<h2><slot /></h2>' },
                SheetDescription: { template: '<p><slot /></p>' },
            },
        },
    });
}

describe('ReminderForm local due dates', () => {
    beforeEach(() => {
        create.mockReset().mockResolvedValue({ id: 'reminder-1' });
        update.mockReset().mockResolvedValue({ id: 'reminder-1' });
    });

    it.each(['2026-09-18T10:00', '2026-01-18T10:00'])(
        'submits the local input %s as an unambiguous instant',
        async (input) => {
            const wrapper = mountForm();

            await wrapper.get('[data-reminder-subject]').setValue('QA');
            await wrapper.get('[data-reminder-due]').setValue(input);
            await wrapper.get('form').trigger('submit');
            await flushPromises();

            expect(create).toHaveBeenCalledWith(
                expect.objectContaining({
                    due_at: new Date(input).toISOString(),
                    record_id: 'record-1',
                }),
            );
        },
    );

    it('converts an offset timestamp to local form time and preserves its instant on save', async () => {
        const reminder: ReminderItem = {
            id: 'reminder-1',
            subject: 'QA',
            note: null,
            type: null,
            dueAt: '2026-09-18T10:00:00+02:00',
            doneAt: null,
            owner: null,
            assignee: null,
            record: null,
        };
        const wrapper = mountForm(reminder);
        const field = wrapper.get<HTMLInputElement>('[data-reminder-due]');

        expect(new Date(field.element.value).toISOString()).toBe(
            '2026-09-18T08:00:00.000Z',
        );
        await wrapper.get('form').trigger('submit');
        await flushPromises();
        expect(update).toHaveBeenCalledWith(
            'reminder-1',
            expect.objectContaining({ due_at: '2026-09-18T08:00:00.000Z' }),
        );
    });

    it('saves an empty due date as null', async () => {
        const wrapper = mountForm();

        await wrapper.get('[data-reminder-subject]').setValue('QA');
        await wrapper.get('form').trigger('submit');
        await flushPromises();
        expect(create).toHaveBeenCalledWith(
            expect.objectContaining({ due_at: null }),
        );
    });
});
