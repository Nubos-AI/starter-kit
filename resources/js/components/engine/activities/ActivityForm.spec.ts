import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ActivityForm from '@/components/engine/activities/ActivityForm.vue';
import { selectStubs } from '@/tests/selectStubs';
import { setUrlDefaults } from '@/wayfinder';

setUrlDefaults({ activeTeam: 'nubos' });
const http = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
    transform: vi.fn(),
}));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    return {
        usePage: () => ({ props: { auth: { user: { id: 'user-1' } } } }),
        useHttp: (data: object) =>
            reactive({
                ...data,
                ...http,
                errors: {},
                hasErrors: false,
                processing: false,
            }),
    };
});
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
beforeEach(() => {
    vi.clearAllMocks();
    http.post.mockResolvedValue({});
    http.put.mockResolvedValue({});
});
describe('ActivityForm', () => {
    it('saves a new activity through the record endpoint and emits success', async () => {
        const wrapper = mount(ActivityForm, {
            props,
            global: { stubs: formStubs },
        });
        await wrapper.get('#activity-subject').setValue('Rückruf');
        await wrapper.get('#activity-result').setValue('Termin vereinbart');
        await wrapper.get('form').trigger('submit');
        await flushPromises();
        expect(http.post).toHaveBeenCalledWith(
            '/nubos/engine/records/record-1/activities',
        );
        expect(wrapper.emitted('saved')).toHaveLength(1);
        const transform = http.transform.mock.calls[0][0];
        expect(
            transform({ occurred_at: '2026-09-19T10:00', subject: 'Rückruf' }),
        ).toEqual({
            occurred_at: new Date('2026-09-19T10:00').toISOString(),
            subject: 'Rückruf',
        });
    });
    it('keeps the form and user input when saving fails', async () => {
        http.post.mockRejectedValue(new Error('offline'));
        const wrapper = mount(ActivityForm, {
            props,
            global: { stubs: formStubs },
        });
        await wrapper.get('#activity-subject').setValue('Rückruf');
        await wrapper.get('form').trigger('submit');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain(
            'nicht gespeichert',
        );
        expect(
            (wrapper.get('#activity-subject').element as HTMLInputElement)
                .value,
        ).toBe('Rückruf');
        expect(wrapper.emitted('saved')).toBeUndefined();
    });
    it('updates an existing activity and preserves its retired type', async () => {
        const wrapper = mount(ActivityForm, {
            props: {
                ...props,
                types: [],
                activity: {
                    id: 'activity-1',
                    subject: 'Besprechung',
                    occurredAt: '2026-09-19T08:00:00Z',
                    result: 'Ergebnis',
                    activityTypeId: 'old-type',
                    typeName: 'Alter Typ',
                    assigneeId: 'user-1',
                    assigneeName: 'Anna',
                },
            },
            global: { stubs: formStubs },
        });
        expect(wrapper.text()).toContain('Alter Typ (gelöscht)');
        await wrapper.get('form').trigger('submit');
        await flushPromises();
        expect(http.put).toHaveBeenCalledWith(
            '/nubos/engine/records/record-1/activities/activity-1',
        );
    });
});
