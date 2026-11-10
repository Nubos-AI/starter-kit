import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { MultiSelect } from '@/components/ui/multi-select';
import WebhookForm from '@/pages/webhooks/Form.vue';
import { comboboxStubs } from '@/tests/selectStubs';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ url: '/', props: {} }),
    Head: { template: '<head-stub><slot /></head-stub>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    Form: {
        name: 'InertiaFormStub',
        props: ['transform', 'onSuccess'],
        template: '<form><slot :errors="{}" :processing="false" /></form>',
    },
    router: {
        on: vi.fn(() => vi.fn()),
        visit: vi.fn(),
        post: vi.fn(),
        delete: vi.fn(),
    },
}));

const EVENT_TYPES = [
    { value: 'record.created', label: 'Record Created' },
    { value: 'record.updated', label: 'Record Updated' },
];

const OBJECT_TYPES = [{ value: 'obj-1', label: 'Firmen' }];

const ROLES = [{ value: 'member', label: 'member' }];

const TEAMS = [{ value: 'team-sales', label: 'Vertrieb Nord' }];

function mountForm(
    mode: 'create' | 'edit',
    subscription: Record<string, unknown> = {},
) {
    return mount(WebhookForm, {
        global: { stubs: comboboxStubs },
        props: {
            mode,
            subscription:
                mode === 'create'
                    ? null
                    : ({
                          id: 'hook-1',
                          name: 'Kundenportal',
                          targetUrl: 'https://hooks.example.test/webhook',
                          authUsername: null,
                          status: 'active',
                          eventTypes: ['record.created'],
                          objectTypeId: null,
                          teamId: null,
                          roleName: 'member',
                          consecutiveFailures: 0,
                          lastError: null,
                          activatedAt: null,
                          rotatedAt: null,
                          ...subscription,
                      } as never),
            secret: null,
            eventTypeOptions: EVENT_TYPES as never,
            objectTypeOptions: OBJECT_TYPES as never,
            roleOptions: ROLES as never,
            teamOptions: TEAMS as never,
        },
    });
}

describe('webhooks/Form', () => {
    it('reveals the secret when it arrives after a rotation', async () => {
        const wrapper = mountForm('edit');

        expect(document.body.querySelector('[data-webhook-secret]')).toBeNull();

        await wrapper.setProps({ secret: 'whsec_rotated_value' });
        await nextTick();

        const shown = document.body.querySelector('[data-webhook-secret]');

        expect(shown?.textContent).toContain('whsec_rotated_value');
    });

    it('keeps saving disabled until a field changes', async () => {
        const wrapper = mountForm('edit');

        expect(wrapper.get('[data-form-save]').attributes('disabled')).toBe('');

        await wrapper.get('#webhook-name').setValue('Partnerportal');

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('treats a changed multi select as an unsaved change', async () => {
        const wrapper = mountForm('edit');

        wrapper
            .findComponent(MultiSelect)
            .vm.$emit('update:modelValue', ['record.updated']);
        await nextTick();

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('asks before leaving with pending changes', async () => {
        const wrapper = mountForm('edit');

        await wrapper.get('#webhook-name').setValue('Partnerportal');
        await wrapper.get('[data-form-cancel]').trigger('click');
        await nextTick();

        expect(document.body.textContent).toContain('Änderungen verwerfen?');
    });

    it('carries the selections into the submitted payload', () => {
        const wrapper = mountForm('create');
        const transform = wrapper
            .findComponent({ name: 'InertiaFormStub' })
            .props('transform') as (
            data: Record<string, unknown>,
        ) => Record<string, unknown>;

        expect(transform({ target_url: 'https://hooks.example.test' })).toEqual(
            {
                target_url: 'https://hooks.example.test',
                auth_username: '',
                auth_password: '',
                roleName: 'member',
                event_types: [],
                object_type_id: null,
                team_id: null,
            },
        );
    });

    it('picks the role from a combobox', () => {
        const wrapper = mountForm('create');

        const role = wrapper.get<HTMLSelectElement>('[data-webhook-role]');

        expect(role.element.tagName).toBe('SELECT');
        expect(role.element.value).toBe('member');
    });

    it('offers "all object types" first in the object type combobox', () => {
        const wrapper = mountForm('create');

        const values = wrapper
            .get('[data-webhook-object-type]')
            .findAll('option')
            .map((option) => option.attributes('value'));

        expect(values).toEqual(['all', 'obj-1']);
    });

    it('sends the picked object type and null for all object types', async () => {
        const wrapper = mountForm('create');

        await wrapper.get('[data-webhook-object-type]').setValue('obj-1');

        const transform = wrapper
            .findComponent({ name: 'InertiaFormStub' })
            .props('transform') as (
            data: Record<string, unknown>,
        ) => Record<string, unknown>;

        expect(transform({}).object_type_id).toBe('obj-1');
    });

    it('explains a pending challenge in an alert', () => {
        const wrapper = mountForm('edit', { status: 'pending' });

        const hint = wrapper.get('[data-webhook-pending-hint]');

        expect(hint.attributes('data-slot')).toBe('alert');
    });

    it('shows the last delivery error as a destructive alert', () => {
        const wrapper = mountForm('edit', { lastError: 'HTTP 500' });

        const error = wrapper.get('[data-webhook-last-error]');

        expect(error.attributes('data-slot')).toBe('alert');
        expect(error.text()).toContain('HTTP 500');
    });

    it('asks for the password again once the destination moves to another server', async () => {
        const wrapper = mountForm('edit', { authUsername: 'hook-user' });

        expect(wrapper.find('[data-webhook-password-required]').exists()).toBe(
            false,
        );

        await wrapper
            .get('#webhook-target-url')
            .setValue('https://hooks.example.test/other-path');

        expect(wrapper.find('[data-webhook-password-required]').exists()).toBe(
            false,
        );

        await wrapper
            .get('#webhook-target-url')
            .setValue('https://attacker.example.org/webhook');

        expect(wrapper.find('[data-webhook-password-required]').exists()).toBe(
            true,
        );

        await wrapper.get('#webhook-auth-password').setValue('fresh-password');

        expect(wrapper.find('[data-webhook-password-required]').exists()).toBe(
            false,
        );
    });

    it('offers the whole system first and then the teams of the tenant', () => {
        const wrapper = mountForm('create');

        const values = wrapper
            .get('[data-webhook-team]')
            .findAll('option')
            .map((option) => option.attributes('value'));

        expect(values).toEqual(['system', 'team-sales']);
        expect(wrapper.get('[data-webhook-team-hint]').text()).toContain(
            'gesamte System',
        );
    });

    it('sends the picked team and null for the whole system', async () => {
        const wrapper = mountForm('create');
        const transform = () =>
            (
                wrapper
                    .findComponent({ name: 'InertiaFormStub' })
                    .props('transform') as (
                    data: Record<string, unknown>,
                ) => Record<string, unknown>
            )({});

        expect(transform().team_id).toBeNull();

        await wrapper.get('[data-webhook-team]').setValue('team-sales');

        expect(transform().team_id).toBe('team-sales');
    });

    it('starts an edit from the stored team and treats a new team as an unsaved change', async () => {
        const wrapper = mountForm('edit', { teamId: 'team-sales' });

        expect(
            wrapper.get<HTMLSelectElement>('[data-webhook-team]').element.value,
        ).toBe('team-sales');
        expect(wrapper.get('[data-form-save]').attributes('disabled')).toBe('');

        await wrapper.get('[data-webhook-team]').setValue('system');

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });
});
