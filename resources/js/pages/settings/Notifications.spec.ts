import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { update as updatePreferences } from '@/actions/App/Http/Controllers/Notifications/NotificationPreferencesController';
import Notifications from '@/pages/settings/Notifications.vue';
import { multiSelectStubs, selectStubs } from '@/tests/selectStubs';

const authProps = vi.hoisted(() => ({
    auth: { authority: 'scope_admin' as string | null, can: {} },
}));

const formErrors = vi.hoisted(() => ({
    current: {} as Record<string, string>,
}));

const push = vi.hoisted(() => ({
    supported: { value: false },
    subscribed: { value: false },
    permission: { value: 'default' },
    busy: { value: false },
    error: { value: null as string | null },
    subscribe: vi.fn(),
    unsubscribe: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    router: { on: vi.fn(() => vi.fn()), visit: vi.fn() },
    usePage: () => ({ url: '/', props: authProps }),
    Head: { template: '<head-stub><slot /></head-stub>' },
    Form: {
        name: 'InertiaFormStub',
        props: ['transform', 'action'],
        setup: () => ({ formErrors }),
        template:
            '<form><slot :errors="formErrors.current" :processing="false" /></form>',
    },
}));

vi.mock('@/composables/usePushSubscription', () => ({
    usePushSubscription: () => push,
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
        global: { stubs: { ...selectStubs, ...multiSelectStubs } },
    });
}

function selectedChannels(
    wrapper: ReturnType<typeof mountPage>,
    selector: string,
): string[] {
    return wrapper
        .get<HTMLSelectElement>(`${selector} select.ui-multi-select`)
        .findAll<HTMLOptionElement>('option')
        .filter((option) => option.element.selected)
        .map((option) => option.attributes('value') ?? '');
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
    it('preselects the channels the tenant has stored as defaults in one multi-select per type', () => {
        const wrapper = mountPage({
            'reminder.due': {
                email: { enabled: true, delivery_mode: 'digest' },
            },
        });

        expect(
            selectedChannels(wrapper, '[data-default-type="reminder.due"]'),
        ).toEqual(['email']);
        expect(
            selectedChannels(wrapper, '[data-default-type="rule.triggered"]'),
        ).toEqual([]);
    });

    it('sends a channel picked in the multi-select as enabled', async () => {
        const wrapper = mountPage();

        const select = wrapper.get<HTMLSelectElement>(
            '[data-default-type="rule.triggered"] select.ui-multi-select',
        );
        select.findAll<HTMLOptionElement>('option')[2].element.selected = true;
        await select.trigger('change');

        expect(tenantTransform(wrapper)().defaults).toContainEqual({
            type: 'rule.triggered',
            channel: 'web-push',
            enabled: true,
            delivery_mode: 'immediate',
        });
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

describe('settings/Notifications — digest picker and admin gating', () => {
    afterEach(() => {
        authProps.auth.authority = 'scope_admin';
    });

    it('carries the chosen frequency and hour in the digest form it sends to the preferences endpoint', async () => {
        const wrapper = mountPage();
        const digest = wrapper
            .findAllComponents({ name: 'InertiaFormStub' })
            .find((form) => form.attributes('data-test') === 'digest-form');

        await wrapper.get('select[name="frequency"]').setValue('weekly');
        await wrapper.get('select[name="hour"]').setValue('9');

        expect(digest?.props('action')).toBe(updatePreferences.url());
        expect(digest?.attributes('method')).toBe('patch');
        expect(
            (digest?.get('[name="frequency"]').element as HTMLSelectElement)
                .value,
        ).toBe('weekly');
        expect(
            (digest?.get('[name="hour"]').element as HTMLSelectElement).value,
        ).toBe('9');
    });

    it('shows the tenant defaults only to a tenant administrator', () => {
        expect(mountPage().find('[data-test="admin-defaults"]').exists()).toBe(
            true,
        );

        authProps.auth.authority = null;

        expect(mountPage().find('[data-test="admin-defaults"]').exists()).toBe(
            false,
        );
    });
});

describe('settings/Notifications — field errors and push switch', () => {
    afterEach(() => {
        formErrors.current = {};
        push.supported.value = false;
        push.error.value = null;
        push.subscribe.mockReset();
    });

    it('binds server errors to the fields the forms render', () => {
        formErrors.current = {
            timezone: 'Die Zeitzone ist ungültig.',
            quiet_hours_end: 'Bitte geben Sie ein Ende an.',
            'preferences.0.delivery_mode': 'Der Zustellmodus ist ungültig.',
        };

        const text = mountPage().text();

        expect(text).toContain('Die Zeitzone ist ungültig.');
        expect(text).toContain('Bitte geben Sie ein Ende an.');
        expect(text).toContain('Der Zustellmodus ist ungültig.');
    });

    it('switches push notifications on through a switch instead of buttons', async () => {
        push.supported.value = true;
        const wrapper = mountPage();

        await wrapper.get('[data-test="push-switch"]').trigger('click');
        await flushPromises();

        expect(push.subscribe).toHaveBeenCalledOnce();
    });

    it('shows a push failure as a destructive alert', () => {
        push.error.value = 'Push konnte nicht aktiviert werden.';

        expect(mountPage().get('[data-test="push-error"]').text()).toBe(
            'Push konnte nicht aktiviert werden.',
        );
    });
});
