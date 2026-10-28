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

    it('keeps the badge small and tucks it against the bell', () => {
        unreadCount.value = 3;

        const classes = mountBell().get('[data-notification-badge]').classes();

        expect(classes).toEqual(
            expect.arrayContaining([
                'h-4',
                'min-w-4',
                'top-1',
                'right-1',
                'tabular-nums',
                'bg-danger-bold',
                'text-inverse',
            ]),
        );
        expect(classes).not.toContain('h-5');
        expect(classes).not.toContain('text-white');
    });
});
