import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import TeamAccessRulesController from '@/actions/App/Http/Controllers/Teams/TeamAccessRulesController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import AccessRules from '@/pages/teams/edit/AccessRules.vue';
import { comboboxStubs, selectStubs } from '@/tests/selectStubs';

const { deleteMock, formErrors } = vi.hoisted(() => ({
    deleteMock: vi.fn(),
    formErrors: {} as Record<string, string>,
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ url: '/', props: {} }),
    Head: { template: '<head-stub><slot /></head-stub>' },
    useForm: (initial: Record<string, unknown>) => ({
        ...initial,
        processing: false,
        errors: formErrors,
        put: vi.fn(),
    }),
    router: {
        on: vi.fn(() => vi.fn()),
        visit: vi.fn(),
        delete: deleteMock,
    },
}));

const objectType = {
    id: 'ot-1',
    slug: 'companies',
    name: 'Firmen',
    fields: [],
};

function mountPage(
    manage: { canManage: boolean; manageReason: string | null } = {
        canManage: true,
        manageReason: null,
    },
): ReturnType<typeof mount> {
    return mount(AccessRules, {
        props: {
            team: { id: 'team-1', name: 'Vertrieb' },
            objectTypes: [objectType],
            rules: [
                {
                    objectTypeId: 'ot-1',
                    filterDefinition: { combinator: 'and', conditions: [] },
                    isActive: true,
                    inheritance: 'intersect',
                },
            ],
            inheritedRules: [],
            inheritanceOptions: [{ value: 'intersect', label: 'Schnittmenge' }],
            ...manage,
        },
        global: {
            stubs: {
                ...selectStubs,
                ...comboboxStubs,
                FilterBuilder: { template: '<div />' },
            },
        },
    });
}

beforeEach(() => {
    deleteMock.mockReset();

    for (const key of Object.keys(formErrors)) {
        delete formErrors[key];
    }
});

describe('teams/AccessRules — removing a rule', () => {
    it('asks for confirmation before deleting the rule', async () => {
        const wrapper = mountPage();

        await wrapper
            .get('[data-access-rule-delete="companies"]')
            .trigger('click');
        await nextTick();

        expect(wrapper.findComponent(ConfirmDialog).props('open')).toBe(true);
        expect(deleteMock).not.toHaveBeenCalled();
    });

    it('deletes the rule of the chosen object type once confirmed', async () => {
        const wrapper = mountPage();

        await wrapper
            .get('[data-access-rule-delete="companies"]')
            .trigger('click');
        wrapper.findComponent(ConfirmDialog).vm.$emit('confirm');
        await nextTick();

        expect(deleteMock).toHaveBeenCalledTimes(1);
        expect(deleteMock.mock.calls[0][0]).toBe(
            TeamAccessRulesController.destroy.url({
                team: 'team-1',
                objectType: 'ot-1',
            }),
        );
    });
});

describe('teams/AccessRules — a refused filter', () => {
    it('shows the reason the server gave for the filter below the filter', () => {
        formErrors.filter_definition =
            'Ein Filterwert passt nicht zum Typ seines Feldes. Bitte prüfen Sie den Filter.';

        const wrapper = mountPage();

        expect(wrapper.get('[data-access-rule-filter-error]').text()).toBe(
            'Ein Filterwert passt nicht zum Typ seines Feldes. Bitte prüfen Sie den Filter.',
        );
    });
});

describe('teams/AccessRules — rules that restrict the viewer', () => {
    const reason =
        'Diese Zugriffsregeln schränken Sie selbst ein, weil Sie dem Team oder einem seiner Unterteams angehören. Ändern kann sie nur eine Administration.';

    it('explains why the rules stay read only and disables removing a rule', async () => {
        const wrapper = mountPage({ canManage: false, manageReason: reason });

        expect(wrapper.get('[data-access-rules-read-only]').text()).toContain(
            reason,
        );
        expect(
            wrapper.get('[data-access-rule-controls]').attributes('disabled'),
        ).toBeDefined();

        const removal = wrapper.get('[data-access-rule-delete="companies"]');

        expect(removal.attributes('disabled')).toBeDefined();

        await removal.trigger('click');
        await nextTick();

        expect(wrapper.findComponent(ConfirmDialog).props('open')).toBe(false);
    });

    it('shows no read only notice to a manager who may change the rules', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-access-rules-read-only]').exists()).toBe(
            false,
        );
        expect(
            wrapper.get('[data-access-rule-controls]').attributes('disabled'),
        ).toBeUndefined();
    });
});
