import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import NotificationBell from '@/components/notifications/NotificationBell.vue';

const unreadCount = ref<number>(0);

vi.mock('@/composables/useNotifications', () => ({
    useNotifications: () => ({
        unreadCount,
        startPolling: vi.fn(),
    }),
}));

function mountBell() {
    return mount(NotificationBell, {
        global: { stubs: { NotificationInbox: true } },
    });
}

describe('NotificationBell', () => {
    beforeEach(() => {
        unreadCount.value = 0;
    });

    it('shows no badge while nothing is unread', () => {
        expect(mountBell().find('[data-notification-badge]').exists()).toBe(
            false,
        );
    });

    it('caps the badge at 99+', () => {
        unreadCount.value = 120;

        expect(mountBell().get('[data-notification-badge]').text()).toBe('99+');
    });
});
