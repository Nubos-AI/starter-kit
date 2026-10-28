import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import SkillForm from '@/pages/skills/Form.vue';
import { multiSelectStubs } from '@/tests/selectStubs';

const { formErrors } = vi.hoisted(() => ({
    formErrors: { value: {} as Record<string, string> },
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ url: '/', props: {} }),
    Head: { template: '<head-stub><slot /></head-stub>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    Form: {
        name: 'InertiaFormStub',
        props: ['transform', 'onSuccess'],
        computed: {
            errors: () => formErrors.value,
        },
        template: '<form><slot :errors="errors" :processing="false" /></form>',
    },
    router: { on: vi.fn(() => vi.fn()), visit: vi.fn() },
}));

const USER_OPTIONS = [
    { value: 'user-1', label: 'Alex Bauer' },
    { value: 'user-2', label: 'Robin Fischer' },
];

function mountForm(
    overrides: Record<string, unknown> = {},
): ReturnType<typeof mount> {
    return mount(SkillForm, {
        props: {
            mode: 'edit',
            skill: {
                id: 'skill-1',
                name: 'Buchhaltung',
                userIds: ['user-1'],
            },
            userOptions: USER_OPTIONS,
            ...overrides,
        },
        global: { stubs: { ...multiSelectStubs } },
    });
}

function userSelect(
    wrapper: ReturnType<typeof mount>,
): ReturnType<ReturnType<typeof mount>['get']> {
    return wrapper.get('#skill-users');
}

function selectedUserIds(wrapper: ReturnType<typeof mount>): string[] {
    return Array.from(
        (userSelect(wrapper).element as HTMLSelectElement).selectedOptions,
    ).map((option) => option.value);
}

function transformOf(
    wrapper: ReturnType<typeof mount>,
): (data: Record<string, unknown>) => Record<string, unknown> {
    return wrapper
        .findComponent({ name: 'InertiaFormStub' })
        .props('transform') as (
        data: Record<string, unknown>,
    ) => Record<string, unknown>;
}

beforeEach(() => {
    formErrors.value = {};
});

describe('skills/Form', () => {
    it('keeps save disabled until a value changes', async () => {
        const wrapper = mountForm();

        expect(wrapper.get('[data-form-save]').attributes('disabled')).toBe('');

        await wrapper.get('#skill-name').setValue('Buchhaltung Nord');

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();

        const untouched = mountForm();

        expect(untouched.get('[data-form-save]').attributes('disabled')).toBe(
            '',
        );

        await userSelect(untouched).setValue(['user-1', 'user-2']);

        expect(
            untouched.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('submits the selected users', async () => {
        const wrapper = mountForm();

        await userSelect(wrapper).setValue(['user-2']);

        expect(transformOf(wrapper)({ name: 'Buchhaltung' })).toMatchObject({
            name: 'Buchhaltung',
            user_ids: ['user-2'],
        });
    });

    it('preselects the currently assigned users', () => {
        expect(selectedUserIds(mountForm())).toEqual(['user-1']);
    });

    it('offers the server options in a selection field instead of an id input', () => {
        const wrapper = mountForm();

        expect(wrapper.findAll('select.ui-multi-select')).toHaveLength(1);
        expect(
            userSelect(wrapper)
                .findAll('option')
                .map((option) => [option.attributes('value'), option.text()]),
        ).toEqual(USER_OPTIONS.map((option) => [option.value, option.label]));
        expect(wrapper.find('input[name="user_ids"]').exists()).toBe(false);
        expect(wrapper.findAll('input')).toHaveLength(1);
        expect(wrapper.get('input').attributes('id')).toBe('skill-name');
    });

    it('offers no user assignment while creating a skill', () => {
        const wrapper = mountForm({ mode: 'create', skill: null });

        expect(wrapper.find('select.ui-multi-select').exists()).toBe(false);
    });

    it('shows the server error for user_ids next to the selection field', () => {
        formErrors.value = {
            user_ids:
                'One or more selected users cannot hold a skill of this tenant.',
        };

        const wrapper = mountForm();
        const group = wrapper.get('.ui-multi-select-wrapper').element
            .parentElement;

        expect(group?.textContent).toContain(
            'One or more selected users cannot hold a skill of this tenant.',
        );
    });

    it('asks before leaving with pending changes', async () => {
        const wrapper = mountForm();

        await wrapper.get('#skill-name').setValue('Buchhaltung Nord');
        await wrapper.get('[data-form-cancel]').trigger('click');
        await nextTick();

        expect(document.body.textContent).toContain('Änderungen verwerfen?');
    });
});
