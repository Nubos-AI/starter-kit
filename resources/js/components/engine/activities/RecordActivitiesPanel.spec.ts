import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import RecordActivitiesPanel from '@/components/engine/activities/RecordActivitiesPanel.vue';
import {
    Sheet,
    SheetContent,
    SheetTitle,
    SheetDescription,
} from '@/components/ui/sheet';
import { setUrlDefaults } from '@/wayfinder';

setUrlDefaults({ activeTeam: 'nubos' });
const server = vi.hoisted(() => ({
    answer: null as unknown,
    requested: [] as string[],
}));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    return {
        Link: { template: '<a><slot /></a>' },
        useHttp: () =>
            reactive({
                response: {
                    data: [
                        {
                            id: 'activity-1',
                            subject: 'Telefonat',
                            occurredAt: '2026-09-19T08:00:00Z',
                            result: '',
                            typeName: 'Anruf',
                            assigneeName: 'Anna',
                        },
                    ],
                    activityTypes: [{ id: 'type-1', name: 'Anruf' }],
                    assignees: [{ id: 'user-1', name: 'Anna' }],
                    canManage: true,
                    meta: { current_page: 1, last_page: 1 },
                },
                processing: false,
                get: vi.fn(function (this: { response: unknown }, url: string) {
                    server.requested.push(url);

                    if (server.answer !== null) {
                        this.response = server.answer;
                    }

                    return Promise.resolve({});
                }),
                cancel: vi.fn(),
            }),
    };
});
vi.mock('@/composables/usePermissions', () => ({
    usePermissions: () => ({ can: () => true }),
}));

const formStub = {
    props: ['activity'],
    emits: ['cancel', 'saved'],
    components: { SheetTitle, SheetDescription },
    template: `<form data-activity-form-stub><SheetTitle>{{ activity ? 'Aktivität bearbeiten' : 'Neue Aktivität' }}</SheetTitle><SheetDescription>Aktivität erfassen</SheetDescription><button type="button" @click="$emit('cancel')">Abbrechen</button><button type="button" @click="$emit('saved')">Speichern</button></form>`,
};
afterEach(() => {
    document.body.innerHTML = '';
    server.answer = null;
    server.requested = [];
});

function mountPanel() {
    return mount(RecordActivitiesPanel, {
        props: { recordId: 'record-1' },
        attachTo: document.body,
        global: { stubs: { ActivityForm: formStub } },
    });
}

describe('RecordActivitiesPanel side panel', () => {
    it('opens the create form in a right sheet and closes on cancel', async () => {
        const wrapper = mountPanel();
        const panel = wrapper.vm as unknown as { list: { response: unknown } };
        panel.list.response = {
            data: [],
            activityTypes: [{ id: 'type-1', name: 'Anruf' }],
            assignees: [],
            canManage: true,
            meta: { current_page: 1, last_page: 1 },
        };
        await flushPromises();
        expect(document.querySelector('[data-activity-form-stub]')).toBeNull();
        expect(
            wrapper.get('[data-activity-new] [data-create-button]').text(),
        ).toBe('Neue Aktivität');
        expect(wrapper.find('[data-activity-new] svg').exists()).toBe(true);
        await wrapper.get('[data-activity-new] button').trigger('click');
        await flushPromises();
        expect(wrapper.getComponent(SheetContent).props('side')).toBe('right');
        expect(
            document.querySelector('[role="dialog"] [data-activity-form-stub]'),
        ).not.toBeNull();
        expect(
            wrapper
                .get('[data-record-activities-panel]')
                .find('[data-activity-form-stub]')
                .exists(),
        ).toBe(false);
        wrapper.getComponent(formStub).vm.$emit('cancel');
        await flushPromises();
        expect(wrapper.getComponent(Sheet).props('open')).toBe(false);
        wrapper.unmount();
    });
    it('opens an existing activity in the same sheet and closes after saving', async () => {
        const wrapper = mountPanel();
        const activity = {
            id: 'activity-1',
            subject: 'Telefonat',
            occurredAt: '2026-09-19T08:00:00Z',
        };
        const panel = wrapper.vm as unknown as { list: { response: unknown } };
        panel.list.response = {
            data: [activity],
            activityTypes: [],
            assignees: [],
            canManage: true,
            meta: { current_page: 1, last_page: 1 },
        };
        await flushPromises();
        const edit = wrapper
            .findAll('button')
            .find((button) => button.text() === 'Bearbeiten');
        await edit?.trigger('click');
        await flushPromises();
        expect(wrapper.getComponent(formStub).props('activity')).toEqual(
            activity,
        );
        expect(wrapper.getComponent(SheetContent).props('side')).toBe('right');
        wrapper.getComponent(formStub).vm.$emit('saved');
        await flushPromises();
        expect(wrapper.getComponent(Sheet).props('open')).toBe(false);
        expect(wrapper.emitted('changed')).toHaveLength(1);
        wrapper.unmount();
    });
});

describe('RecordActivitiesPanel quick links', () => {
    it('links an activity to its source but not back to the record it sits on', async () => {
        server.answer = {
            data: [
                {
                    id: 'activity-1',
                    subject: 'Telefonat',
                    occurredAt: '2026-09-19T08:00:00Z',
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
                    id: 'activity-2',
                    subject: 'Ohne Herkunft',
                    occurredAt: '2026-09-18T08:00:00Z',
                    links: [],
                },
            ],
            activityTypes: [],
            assignees: [],
            canManage: false,
            meta: { current_page: 1, last_page: 1 },
        };
        const wrapper = mountPanel();
        await flushPromises();

        expect(server.requested).toEqual([
            expect.stringContaining('/records/record-1/activities'),
        ]);

        const items = wrapper.findAll('[data-activity-item]');
        const links = items[0].findAll('[data-source-link]');

        expect(links).toHaveLength(1);
        expect(links[0].attributes('data-source-link')).toBe('email_message');
        expect(links[0].attributes('href')).toBe('/nubos/mails/mail-1');
        expect(links[0].text()).toBe('E-Mail: Angebot');
        expect(items[1].find('[data-source-link]').exists()).toBe(false);
        wrapper.unmount();
    });
});
