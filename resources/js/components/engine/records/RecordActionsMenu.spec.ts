import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import RecordActionsMenu from '@/components/engine/records/RecordActionsMenu.vue';

const dropdownStubs = {
    DropdownMenu: { template: '<div><slot /></div>' },
    DropdownMenuTrigger: { template: '<div><slot /></div>' },
    DropdownMenuContent: { template: '<div><slot /></div>' },
    DropdownMenuSeparator: { template: '<hr />' },
    DropdownMenuItem: {
        props: ['disabled', 'variant', 'title'],
        emits: ['select'],
        template:
            '<button type="button" :disabled="disabled" :data-variant="variant" :title="title" @click="$emit(\'select\')"><slot /></button>',
    },
};

function mountMenu(
    props: Record<string, unknown> = {},
): ReturnType<typeof mount> {
    return mount(RecordActionsMenu, {
        props: { canManagePanels: true, ...props },
        global: { stubs: dropdownStubs },
    });
}

describe('RecordActionsMenu', () => {
    it('offers the four actions in the agreed order', () => {
        const wrapper = mountMenu();

        expect(
            wrapper
                .findAll('[data-record-action]')
                .map((item) => item.attributes('data-record-action')),
        ).toEqual(['duplicate', 'merge', 'delete', 'panels']);
    });

    it('drops the panel settings when the tenant switched them off', () => {
        const wrapper = mountMenu({ canManagePanels: false });

        expect(
            wrapper
                .findAll('[data-record-action]')
                .map((item) => item.attributes('data-record-action')),
        ).toEqual(['duplicate', 'merge', 'delete']);
    });

    it('lets a trashed record still be tidied up visually', async () => {
        const wrapper = mountMenu({ trashed: true });

        const panels = wrapper.get('[data-record-action="panels"]');

        expect(panels.attributes('disabled')).toBeUndefined();

        await panels.trigger('click');

        expect(wrapper.emitted('manage-panels')).toHaveLength(1);
    });

    it('blocks every writing action on a trashed record', async () => {
        const wrapper = mountMenu({ trashed: true });

        for (const action of ['duplicate', 'merge', 'delete']) {
            const item = wrapper.get(`[data-record-action="${action}"]`);

            expect(item.attributes('disabled')).toBeDefined();

            await item.trigger('click');
        }

        expect(wrapper.emitted('duplicate')).toBeUndefined();
        expect(wrapper.emitted('merge')).toBeUndefined();
        expect(wrapper.emitted('delete')).toBeUndefined();
    });

    it('opens the merge from a live record', async () => {
        const wrapper = mountMenu();
        const merge = wrapper.get('[data-record-action="merge"]');

        expect(merge.attributes('disabled')).toBeUndefined();
        expect(merge.attributes('title')).toBeUndefined();

        await merge.trigger('click');

        expect(wrapper.emitted('merge')).toHaveLength(1);
    });

    it('disables the merge with its reason instead of hiding it', async () => {
        const wrapper = mountMenu({ canMerge: false });
        const merge = wrapper.get('[data-record-action="merge"]');

        expect(wrapper.findAll('[data-record-action]')).toHaveLength(4);
        expect(merge.attributes('disabled')).toBeDefined();
        expect(merge.attributes('title')).toBe(
            'Ihnen fehlt die Berechtigung, diesen Datensatz zusammenzuführen.',
        );

        await merge.trigger('click');

        expect(wrapper.emitted('merge')).toBeUndefined();
    });

    it('carries no document action any more — that lives in the detail tabs', () => {
        expect(
            mountMenu().find('[data-record-action="document"]').exists(),
        ).toBe(false);
    });

    it('hides no action from a user who may not delete but disables it', () => {
        const wrapper = mountMenu({ canDelete: false });

        expect(wrapper.findAll('[data-record-action]')).toHaveLength(4);
        expect(
            wrapper.get('[data-record-action="delete"]').attributes('disabled'),
        ).toBeDefined();
    });
});
