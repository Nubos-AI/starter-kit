import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import TeamSelectionCard from '@/components/teams/TeamSelectionCard.vue';
import MultiSelect from '@/components/ui/multi-select/MultiSelect.vue';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ url: '/', props: {} }),
    Form: {
        name: 'InertiaFormStub',
        props: ['transform', 'onSuccess', 'options'],
        template: '<form><slot :errors="{}" :processing="false" /></form>',
    },
    router: { on: vi.fn(() => vi.fn()), visit: vi.fn() },
}));

function mountCard(): ReturnType<typeof mount> {
    return mount(TeamSelectionCard, {
        props: {
            teamId: 'team-1',
            field: 'member_ids',
            selected: ['user-1'],
            options: [
                { value: 'user-1', label: 'Alex' },
                { value: 'user-2', label: 'Robin' },
            ],
            title: 'Mitglieder',
            description: 'Beschreibung',
            label: 'Zugeordnete Mitglieder',
            placeholder: 'Mitglieder auswählen',
            emptyLabel: 'Keine Benutzer',
        },
    });
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

describe('teams/TeamSelectionCard', () => {
    it('preselects the current assignment', () => {
        expect(
            mountCard().findComponent(MultiSelect).props('modelValue'),
        ).toEqual(['user-1']);
    });

    it('keeps saving disabled until the selection changes', async () => {
        const wrapper = mountCard();

        expect(wrapper.get('[data-form-save]').attributes('disabled')).toBe('');

        wrapper
            .findComponent(MultiSelect)
            .vm.$emit('update:modelValue', ['user-1', 'user-2']);
        await nextTick();

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('submits only its own field, so saving one tab leaves the others untouched', async () => {
        const wrapper = mountCard();

        wrapper
            .findComponent(MultiSelect)
            .vm.$emit('update:modelValue', ['user-2']);
        await nextTick();

        expect(transformOf(wrapper)({ name: 'Sales' })).toEqual({
            member_ids: ['user-2'],
        });
    });

    it('places the save row inside the card it saves', () => {
        expect(
            mountCard()
                .find('[data-team-selection-card] [data-form-save]')
                .exists(),
        ).toBe(true);
    });

    it('asks before leaving with a pending selection', async () => {
        const wrapper = mountCard();

        wrapper
            .findComponent(MultiSelect)
            .vm.$emit('update:modelValue', ['user-2']);
        await nextTick();
        await wrapper.get('[data-form-cancel]').trigger('click');
        await nextTick();

        expect(document.body.textContent).toContain('Änderungen verwerfen?');
    });
});
