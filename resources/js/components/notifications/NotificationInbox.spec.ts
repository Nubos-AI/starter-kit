import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import NotificationInbox from '@/components/notifications/NotificationInbox.vue';

vi.mock('@/composables/useNotifications', () => ({
    useNotifications: () => ({
        items: ref([]),
        loading: ref(false),
        error: ref(null),
        load: vi.fn(),
        markRead: vi.fn(),
        markUnread: vi.fn(),
        snooze: vi.fn(),
        archive: vi.fn(),
        remove: vi.fn(),
    }),
}));

const passthrough = { template: '<div><slot /></div>' };

function mountInbox() {
    return mount(NotificationInbox, {
        props: { open: true },
        global: {
            stubs: {
                Sheet: passthrough,
                SheetContent: passthrough,
                SheetHeader: passthrough,
                SheetTitle: passthrough,
                SheetDescription: passthrough,
            },
        },
    });
}

describe('NotificationInbox — every tab speaks German', () => {
    it('names the snooze tab in German', () => {
        const labels = mountInbox()
            .findAll('[role="tab"]')
            .map((tab) => tab.text());

        expect(labels).toEqual(['Nachrichten', 'Zurückgestellt', 'Archiviert']);
    });

    it('says in German that nothing is snoozed', async () => {
        const wrapper = mountInbox();
        const snoozeTab = wrapper.findAll('[role="tab"]')[1];

        await snoozeTab.trigger('click');

        expect(wrapper.text()).toContain('Nichts zurückgestellt.');
        expect(wrapper.text()).not.toContain('Nothing snoozed.');
    });
});
