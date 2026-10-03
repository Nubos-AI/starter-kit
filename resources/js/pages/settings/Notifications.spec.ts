import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import Notifications from '@/pages/settings/Notifications.vue';
import { selectStubs } from '@/tests/selectStubs';

const authProps = { auth: { authority: 'scope_admin', can: {} } };

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ url: '/', props: authProps }),
    Head: { template: '<head-stub><slot /></head-stub>' },
    Form: {
        name: 'InertiaFormStub',
        props: ['transform', 'action'],
        template: '<form><slot :errors="{}" :processing="false" /></form>',
    },
}));

vi.mock('@/composables/usePushSubscription', () => ({
    usePushSubscription: () => ({
        supported: { value: false },
        subscribed: { value: false },
        permission: { value: 'default' },
        busy: { value: false },
        error: { value: null },
        subscribe: vi.fn(),
        unsubscribe: vi.fn(),
    }),
}));

const types = [
    { key: 'reminder.due', label: 'Reminder due' },
    { key: 'rule.triggered', label: 'Rule triggered' },
];

type PreferenceMap = Record<
    string,
    Record<string, { enabled: boolean; delivery_mode: string }>
>;

function mountPage(defaults: PreferenceMap = {}) {
    return mount(Notifications, {
        props: { types, preferences: {}, defaults },
        global: { stubs: selectStubs },
    });
}

function defaultCheckbox(
    wrapper: ReturnType<typeof mountPage>,
    type: string,
    channel: string,
) {
    return wrapper.findAll(
        '[data-test="admin-defaults"] button[role=checkbox]',
    )[
        types.findIndex((entry) => entry.key === type) * 3 +
            ['in-app', 'email', 'web-push'].indexOf(channel)
    ];
}

function tenantTransform(wrapper: ReturnType<typeof mountPage>) {
    const forms = wrapper.findAllComponents({ name: 'InertiaFormStub' });

    return forms[forms.length - 1].props('transform') as () => {
        defaults: Array<{
            type: string;
            channel: string;
            enabled: boolean;
            delivery_mode: string;
        }>;
    };
}

describe('settings/Notifications tenant defaults', () => {
    it('checks the channels the tenant has stored as defaults', () => {
        const wrapper = mountPage({
            'reminder.due': {
                email: { enabled: true, delivery_mode: 'digest' },
            },
        });

        expect(
            defaultCheckbox(wrapper, 'reminder.due', 'email').attributes(
                'aria-checked',
            ),
        ).toBe('true');
        expect(
            defaultCheckbox(wrapper, 'reminder.due', 'in-app').attributes(
                'aria-checked',
            ),
        ).toBe('false');
    });

    it('submits one entry per type and channel and keeps the stored delivery mode', () => {
        const wrapper = mountPage({
            'reminder.due': {
                email: { enabled: true, delivery_mode: 'digest' },
            },
        });

        const payload = tenantTransform(wrapper)();

        expect(payload.defaults).toHaveLength(6);
        expect(payload.defaults).toContainEqual({
            type: 'reminder.due',
            channel: 'email',
            enabled: true,
            delivery_mode: 'digest',
        });
        expect(payload.defaults).toContainEqual({
            type: 'rule.triggered',
            channel: 'web-push',
            enabled: false,
            delivery_mode: 'immediate',
        });
    });
});
