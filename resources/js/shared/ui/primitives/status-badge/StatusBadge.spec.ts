import { render, screen } from '@testing-library/vue';
import { describe, expect, it } from 'vitest';
import StatusBadge from '@/shared/ui/primitives/status-badge/StatusBadge.vue';
import { IMPORT_JOB_STATUS as RUN_STATUS } from '@/shared/lib/statusMaps';
import { t } from '@/shared/testing/i18n';

const badgeStub = {
    props: ['variant'],
    template: '<span data-slot="badge" :data-variant="variant" v-bind="$attrs"><slot /></span>',
};

interface BadgeProps {
    status: string;
    label?: string;
}

function renderBadge(props: BadgeProps, attrs: Record<string, string> = {}) {
    return render(StatusBadge, {
        props: { map: RUN_STATUS, ...props },
        attrs,
        global: { stubs: { Badge: badgeStub } },
    });
}

describe('StatusBadge', () => {
    it('renders the mapped label and variant for a failed run', () => {
        const { container } = renderBadge({ status: 'failed' });

        expect(container.textContent?.trim()).toBe(t(RUN_STATUS.failed.label));
        expect(container.querySelector('[data-slot="badge"]')?.getAttribute('data-variant')).toBe(
            RUN_STATUS.failed.variant,
        );
    });

    it('falls back to the raw status value when it is unknown', () => {
        const { container } = renderBadge({ status: 'mystery' });

        expect(container.textContent?.trim()).toBe('mystery');
    });

    it('shows a server-provided label verbatim while the map still picks the variant', () => {
        const { container } = renderBadge({ status: 'completed', label: 'Abgeschlossen am Montag' });

        expect(screen.getByText('Abgeschlossen am Montag')).toBeTruthy();
        expect(container.querySelector('[data-slot="badge"]')?.getAttribute('data-variant')).toBe(
            RUN_STATUS.completed.variant,
        );
    });

    it('passes data attributes through to the badge', () => {
        const { container } = renderBadge({ status: 'pending' }, { 'data-run-status': 'pending' });

        expect(container.querySelector('[data-slot="badge"][data-run-status="pending"]')).not.toBeNull();
    });
});
