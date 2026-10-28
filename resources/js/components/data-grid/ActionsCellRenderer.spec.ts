import { Pause, Play, Trash2 } from '@lucide/vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { ActionsCellRenderer } from '@/components/data-grid/ActionsCellRenderer';
import type { RowAction } from '@/types/rowAction';

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
}));

interface Row {
    id: string;
    status: string;
}

function mountRenderer(actions: RowAction<Row>[], data: Row | undefined) {
    return mount(ActionsCellRenderer, {
        props: { params: { data, actions } as never },
    });
}

describe('ActionsCellRenderer', () => {
    it('renders only the actions whose isVisible returns true', () => {
        const row: Row = { id: '1', status: 'active' };
        const actions: RowAction<Row>[] = [
            {
                icon: Play,
                label: 'Aktivieren',
                isVisible: (r) => r.status === 'draft',
                onClick: () => {},
            },
            {
                icon: Pause,
                label: 'Pausieren',
                isVisible: (r) => r.status === 'active',
                onClick: () => {},
            },
        ];

        const wrapper = mountRenderer(actions, row);

        expect(wrapper.findAll('button')).toHaveLength(1);
        expect(wrapper.get('button').attributes('aria-label')).toBe(
            'Pausieren',
        );
    });

    it('invokes onClick with the row when an action is pressed', async () => {
        const onClick = vi.fn();
        const row: Row = { id: '7', status: 'active' };

        const wrapper = mountRenderer(
            [
                {
                    icon: Trash2,
                    label: 'Delete',
                    variant: 'destructive',
                    onClick,
                },
            ],
            row,
        );

        await wrapper.get('button').trigger('click');

        expect(onClick).toHaveBeenCalledWith(row);
        expect(wrapper.get('button').classes()).toContain('icon-danger');
    });

    it('renders nothing when the row is missing', () => {
        const wrapper = mountRenderer(
            [{ icon: Trash2, label: 'Delete', onClick: () => {} }],
            undefined,
        );

        expect(wrapper.find('button').exists()).toBe(false);
    });

    it('disables the action and shows the reason as title when isDisabled returns true', () => {
        const row: Row = { id: '3', status: 'system' };

        const wrapper = mountRenderer(
            [
                {
                    icon: Trash2,
                    label: 'Delete',
                    variant: 'destructive',
                    isDisabled: () => true,
                    disabledReason: () => 'System role - protected',
                    onClick: () => {},
                },
            ],
            row,
        );

        const button = wrapper.get('button');

        expect(button.attributes('disabled')).toBeDefined();
        expect(button.attributes('title')).toBe('System role - protected');
        expect(button.attributes('aria-label')).toBe('Delete');
    });

    it('falls back to the label as title when the enabled action has no reason', () => {
        const row: Row = { id: '4', status: 'active' };

        const wrapper = mountRenderer(
            [
                {
                    icon: Trash2,
                    label: 'Delete',
                    isDisabled: () => false,
                    disabledReason: () => 'Nicht sichtbar da aktiv',
                    onClick: () => {},
                },
            ],
            row,
        );

        const button = wrapper.get('button');

        expect(button.attributes('disabled')).toBeUndefined();
        expect(button.attributes('title')).toBe('Delete');
    });
});
