import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import StatusBadge from '@/components/ui/status-badge/StatusBadge.vue';
import { IMPORT_JOB_STATUS as RUN_STATUS } from '@/lib/statusMaps';

describe('StatusBadge', () => {
    it('renders the mapped label and destructive variant for a failed run', () => {
        const wrapper = mount(StatusBadge, {
            props: { map: RUN_STATUS, status: 'failed' },
        });

        expect(wrapper.text()).toBe('Fehlgeschlagen');
        expect(wrapper.get('[data-slot="badge"]').classes()).toContain(
            'bg-danger-bold',
        );
    });

    it('falls back to the raw status value when it is unknown', () => {
        const wrapper = mount(StatusBadge, {
            props: { map: RUN_STATUS, status: 'mystery' },
        });

        expect(wrapper.text()).toBe('mystery');
    });
});
