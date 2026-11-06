import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import TeamReparentDialog from '@/components/teams/TeamReparentDialog.vue';
import type { TeamTreeNode } from '@/types/teams';

type Wrapper = ReturnType<typeof mount>;

const passthrough = { template: '<div><slot /></div>' };

const stubs = {
    Dialog: passthrough,
    DialogContent: passthrough,
    DialogHeader: passthrough,
    DialogFooter: passthrough,
    DialogTitle: passthrough,
    DialogDescription: passthrough,
};

const headquartersId = '01TEAMHEADQUARTERS00000001';
const salesId = '01TEAMSALES00000000000002';
const northId = '01TEAMSALESNORTH0000000003';
const southId = '01TEAMSALESSOUTH0000000004';
const marketingId = '01TEAMMARKETING00000000005';

function node(overrides: Partial<TeamTreeNode> = {}): TeamTreeNode {
    return {
        id: headquartersId,
        name: 'Headquarters',
        slug: 'headquarters',
        parentTeamId: null,
        depth: 0,
        descendantTeamIds: [],
        can_delete: true,
        delete_reason: null,
        ...overrides,
    };
}

const headquarters = node({
    descendantTeamIds: [salesId, northId, southId, marketingId],
});

const sales = node({
    id: salesId,
    name: 'Sales',
    slug: 'sales',
    parentTeamId: headquartersId,
    depth: 1,
    descendantTeamIds: [northId, southId],
});

const north = node({
    id: northId,
    name: 'Sales North',
    slug: 'sales-north',
    parentTeamId: salesId,
    depth: 2,
});

const south = node({
    id: southId,
    name: 'Sales South',
    slug: 'sales-south',
    parentTeamId: salesId,
    depth: 2,
});

const marketing = node({
    id: marketingId,
    name: 'Marketing',
    slug: 'marketing',
    parentTeamId: headquartersId,
    depth: 1,
});

const nodes = [headquarters, sales, north, south, marketing];

function mountDialog(props: Record<string, unknown> = {}): Wrapper {
    return mount(TeamReparentDialog, {
        props: {
            open: true,
            subject: sales,
            nodes,
            errorMessage: null,
            processing: false,
            ...props,
        },
        global: { stubs },
    });
}

function candidateIds(wrapper: Wrapper): (string | undefined)[] {
    return wrapper
        .findAll('[data-candidate-id]')
        .map((option) => option.attributes('data-candidate-id'));
}

describe('TeamReparentDialog — candidate list (SC-10)', () => {
    it('leaves out the subject and every descendant of the subject', async () => {
        const wrapper = mountDialog();

        expect(candidateIds(wrapper)).toEqual([headquartersId, marketingId]);
        expect(candidateIds(wrapper)).not.toContain(salesId);
        expect(candidateIds(wrapper)).not.toContain(northId);
        expect(candidateIds(wrapper)).not.toContain(southId);
        expect(wrapper.text()).toContain('Marketing');
        expect(wrapper.text()).not.toContain('Sales North');

        await wrapper
            .find(`[data-candidate-id="${marketingId}"]`)
            .trigger('click');
        await wrapper.find('[data-reparent-confirm]').trigger('click');

        expect(wrapper.emitted('submit')).toHaveLength(1);
        expect(wrapper.emitted('submit')![0]).toEqual([marketingId]);
    });

    it('offers a root option that submits a null parent', async () => {
        const wrapper = mountDialog();
        const rootOption = wrapper.find('[data-root-option]');

        expect(rootOption.exists()).toBe(true);
        expect(rootOption.text()).not.toBe('');

        await rootOption.trigger('click');
        await wrapper.find('[data-reparent-confirm]').trigger('click');

        expect(wrapper.emitted('submit')).toHaveLength(1);
        expect(wrapper.emitted('submit')![0]).toEqual([null]);
    });
});

describe('TeamReparentDialog — visibility warning (SC-10)', () => {
    it('names the number of affected teams before the move is confirmed', () => {
        const wrapper = mountDialog();
        const warning = wrapper.find('[data-visibility-warning]');

        expect(warning.exists()).toBe(true);
        expect(warning.text()).toContain(
            String(sales.descendantTeamIds.length + 1),
        );
        expect(wrapper.emitted('submit')).toBeUndefined();

        const leaf = mountDialog({ subject: north });

        expect(leaf.find('[data-visibility-warning]').text()).toContain('1');
    });
});

describe('TeamReparentDialog — feedback surface (SC-10)', () => {
    it('renders the handed error message and nothing when there is none', () => {
        const quiet = mountDialog();
        const failed = mountDialog({
            errorMessage: 'The selected parent team does not exist.',
        });

        expect(quiet.find('[data-reparent-error]').exists()).toBe(false);
        expect(quiet.text()).not.toContain(
            'The selected parent team does not exist.',
        );
        expect(failed.find('[data-reparent-error]').text()).toContain(
            'The selected parent team does not exist.',
        );
    });

    it('blocks the confirmation without a selection and while a request is running', async () => {
        const untouched = mountDialog();
        const confirm = untouched.find('[data-reparent-confirm]');

        expect(confirm.attributes('disabled')).toBeDefined();

        await confirm.trigger('click');

        expect(untouched.emitted('submit')).toBeUndefined();

        const busy = mountDialog({ processing: true });

        await busy
            .find(`[data-candidate-id="${marketingId}"]`)
            .trigger('click');

        expect(
            busy.find('[data-reparent-confirm]').attributes('disabled'),
        ).toBeDefined();

        await busy.find('[data-reparent-confirm]').trigger('click');

        expect(busy.emitted('submit')).toBeUndefined();
    });
});
