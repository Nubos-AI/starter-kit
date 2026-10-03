import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { MultiSelect } from '@/components/ui/multi-select';
import { Select } from '@/components/ui/select';
import WebhookForm from '@/pages/webhooks/Form.vue';

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

function mountForm(mode: 'create' | 'edit') {
    return mount(WebhookForm, {
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
                          roleName: 'member',
                          consecutiveFailures: 0,
                          lastError: null,
                          activatedAt: null,
                          rotatedAt: null,
                      } as never),
            secret: null,
            eventTypeOptions: EVENT_TYPES as never,
            objectTypeOptions: OBJECT_TYPES as never,
            roleOptions: ROLES as never,
        },
    });
}

describe('webhooks/Form', () => {
    it('submits through a save button in both modes', () => {
        for (const mode of ['create', 'edit'] as const) {
            const wrapper = mountForm(mode);
            const submit = wrapper
                .findAll('button')
                .find((button) => button.attributes('type') === 'submit')!;

            expect(submit.text()).toBe('Speichern');
            expect(submit.attributes('data-create-button')).toBeUndefined();
        }
    });

    it('uses the shared select components instead of native selects', () => {
        const wrapper = mountForm('create');

        expect(wrapper.find('select').exists()).toBe(false);
        expect(wrapper.findAllComponents(Select).length).toBe(2);
        expect(wrapper.findComponent(MultiSelect).exists()).toBe(true);
    });

    it('offers a name and basic auth credentials', () => {
        const wrapper = mountForm('create');

        expect(wrapper.find('#webhook-name').exists()).toBe(true);
        expect(wrapper.find('#webhook-auth-username').exists()).toBe(true);
        expect(wrapper.find('#webhook-auth-password').attributes('type')).toBe(
            'password',
        );
    });

    it('reveals the secret when it arrives after a rotation', async () => {
        const wrapper = mountForm('edit');

        expect(document.body.querySelector('[data-webhook-secret]')).toBeNull();

        await wrapper.setProps({ secret: 'whsec_rotated_value' });
        await nextTick();

        const shown = document.body.querySelector('[data-webhook-secret]');

        expect(shown?.textContent).toContain('whsec_rotated_value');
    });

    it('places the event types below the object type filter', () => {
        const wrapper = mountForm('create');
        const labels = wrapper.findAll('label').map((label) => label.text());

        expect(labels.indexOf('Objekttyp-Filter')).toBeLessThan(
            labels.indexOf('Event-Typen'),
        );
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
            },
        );
    });
});
