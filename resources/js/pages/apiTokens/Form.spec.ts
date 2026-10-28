import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { MultiSelect } from '@/components/ui/multi-select';
import type { ObjectTypeAccess } from '@/lib/apiTokenAccess';
import ApiTokenForm from '@/pages/apiTokens/Form.vue';

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

const OBJECT_TYPES = [
    { value: 'companies', label: 'Firmen' },
    { value: 'contacts', label: 'Kontakte' },
];

function mountForm() {
    return mount(ApiTokenForm, {
        props: { objectTypeOptions: OBJECT_TYPES },
    });
}

function transformOf(wrapper: ReturnType<typeof mountForm>) {
    return wrapper
        .findComponent({ name: 'InertiaFormStub' })
        .props('transform') as (
        data: Record<string, unknown>,
    ) => Record<string, unknown>;
}

async function selectObjectTypes(
    wrapper: ReturnType<typeof mountForm>,
    slugs: string[],
): Promise<void> {
    wrapper.findComponent(MultiSelect).vm.$emit('update:modelValue', slugs);
    await nextTick();
}

describe('apiTokens/Form', () => {
    it('uses the shared controls instead of native selects or checkboxes', () => {
        const wrapper = mountForm();

        expect(wrapper.find('select').exists()).toBe(false);
        expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false);
        expect(wrapper.findComponent(MultiSelect).exists()).toBe(true);
    });

    it('renders name, object types and expiry but no role selection', () => {
        const wrapper = mountForm();
        const labels = wrapper.findAll('label').map((label) => label.text());

        expect(wrapper.find('#api-token-name').exists()).toBe(true);
        expect(wrapper.find('#api-token-expires').attributes('type')).toBe(
            'datetime-local',
        );
        expect(labels).toContain('Objekttypen');
        expect(labels).not.toContain('Rolle');
    });

    it('shows no access row before an object type is chosen', () => {
        const wrapper = mountForm();

        expect(wrapper.find('[data-api-token-access-matrix]').exists()).toBe(
            false,
        );
    });

    it('adds a read granting row per chosen object type', async () => {
        const wrapper = mountForm();

        await selectObjectTypes(wrapper, ['companies', 'contacts']);

        const labels = wrapper.findAll('label').map((label) => label.text());

        expect(wrapper.find('[data-api-token-access-matrix]').exists()).toBe(
            true,
        );
        expect(labels).toContain('Firmen');
        expect(labels).toContain('Kontakte');
        expect(transformOf(wrapper)({}).objectTypeAccess).toEqual([
            { objectType: 'companies', levels: ['read'] },
            { objectType: 'contacts', levels: ['read'] },
        ]);
    });

    it('submits the level chosen per object type', async () => {
        const wrapper = mountForm();

        await selectObjectTypes(wrapper, ['companies', 'contacts']);

        wrapper
            .findAllComponents(MultiSelect)[2]
            .vm.$emit('update:modelValue', ['read', 'write']);
        await nextTick();

        expect(transformOf(wrapper)({}).objectTypeAccess).toEqual([
            { objectType: 'companies', levels: ['read'] },
            { objectType: 'contacts', levels: ['read', 'write'] },
        ]);
    });

    it('drops the levels of an object type that is deselected again', async () => {
        const wrapper = mountForm();

        await selectObjectTypes(wrapper, ['companies', 'contacts']);
        await selectObjectTypes(wrapper, ['contacts']);

        expect(transformOf(wrapper)({}).objectTypeAccess).toEqual([
            { objectType: 'contacts', levels: ['read'] },
        ]);
    });

    it('submits through a single right aligned save button', () => {
        const wrapper = mountForm();
        const submit = wrapper
            .findAll('button')
            .filter((button) => button.attributes('type') === 'submit');

        expect(submit).toHaveLength(1);
        expect(submit[0].attributes('data-create-button')).toBeUndefined();
        expect(submit[0].text()).toBe('Speichern');
        expect(wrapper.get('[data-form-actions]').classes()).toContain(
            'justify-end',
        );
    });

    it('keeps saving disabled until a field changes', async () => {
        const wrapper = mountForm();

        expect(wrapper.get('[data-form-save]').attributes('disabled')).toBe('');

        await wrapper.get('#api-token-name').setValue('CI-Integration');

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('treats a changed object type selection as an unsaved change', async () => {
        const wrapper = mountForm();

        await selectObjectTypes(wrapper, ['companies']);

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('sends an empty expiry as null', () => {
        const wrapper = mountForm();

        expect(transformOf(wrapper)({ name: 'CI' }).expiresAt).toBeNull();
    });

    it('keeps the submitted access rows typed', async () => {
        const wrapper = mountForm();

        await selectObjectTypes(wrapper, ['companies']);

        const access = transformOf(wrapper)({})
            .objectTypeAccess as ObjectTypeAccess[];

        expect(access[0].objectType).toBe('companies');
        expect(access[0].levels).toEqual(['read']);
    });
});
