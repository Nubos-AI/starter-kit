import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import NotificationItem from '@/components/notifications/NotificationItem.vue';
import type { NotificationItem as Item } from '@/composables/useNotifications';

function makeItem(overrides: Partial<Item> = {}): Item {
    return {
        id: 'inbox-1',
        type: 'automation.notification',
        data: {},
        priority: 'normal',
        readAt: null,
        snoozedUntil: null,
        archivedAt: null,
        createdAt: '2026-07-15T09:13:00.000Z',
        ...overrides,
    };
}

describe('NotificationItem', () => {
    it('renders the configured title and body from data', () => {
        const wrapper = mount(NotificationItem, {
            props: {
                item: makeItem({
                    data: {
                        title: 'Neue Company angelegt',
                        body: 'Es wurde eine neue Company angelegt (Automation-Test).',
                    },
                }),
            },
        });

        expect(wrapper.text()).toContain('Neue Company angelegt');
        expect(wrapper.text()).toContain(
            'Es wurde eine neue Company angelegt (Automation-Test).',
        );
        expect(wrapper.text()).not.toContain('Automation notification');
    });

    it('falls back to the humanized type when no data title is present', () => {
        const wrapper = mount(NotificationItem, {
            props: { item: makeItem({ data: {} }) },
        });

        expect(wrapper.text()).toContain('Automation notification');
    });

    it('emits delete with the item id when the delete button is clicked', async () => {
        const wrapper = mount(NotificationItem, {
            props: { item: makeItem({ id: 'inbox-42' }) },
        });

        await wrapper.get('[aria-label="Löschen"]').trigger('click');

        expect(wrapper.emitted('delete')).toEqual([['inbox-42']]);
    });
});
