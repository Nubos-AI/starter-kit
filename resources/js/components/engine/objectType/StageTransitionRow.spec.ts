import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import StageTransitionRow from '@/components/engine/objectType/StageTransitionRow.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

const passthrough = { template: '<div><slot /></div>' };

const rowStubs = {
    TooltipProvider: passthrough,
    Tooltip: passthrough,
    TooltipTrigger: passthrough,
    TooltipContent: passthrough,
};

type Wrapper = ReturnType<typeof mount>;

function mountRow(overrides: Record<string, unknown> = {}): Wrapper {
    return mount(StageTransitionRow, {
        props: {
            targetLabel: 'Gewonnen',
            transitionId: 'tr-1',
            gateSummary: [],
            gateHref: '/engine/object-types/deals/edit/transitions/tr-1',
            gateLabel: 'Bedingung für Offen nach Gewonnen bearbeiten',
            gateLockedReason: null,
            removable: true,
            removeLabel: 'Wechsel Offen nach Gewonnen entfernen',
            removeLockedReason: null,
            ...overrides,
        } as never,
        global: { stubs: rowStubs },
    });
}

describe('StageTransitionRow', () => {
    it('names the target and states that no condition applies', () => {
        const wrapper = mountRow();

        expect(wrapper.text()).toContain('Gewonnen');
        expect(wrapper.get('[data-gate-summary]').text()).toContain(
            'Keine Bedingung',
        );
    });

    it('spells out every rule of the condition', () => {
        const wrapper = mountRow({
            gateSummary: ['Betrag ausgefüllt', 'Betrag > 100'],
        });
        const summary = wrapper.get('[data-gate-summary]').text();

        expect(summary).toContain('Nur wenn');
        expect(summary).toContain('Betrag ausgefüllt');
        expect(summary).toContain('Betrag > 100');
    });

    it('links the condition of the transition to its editor', () => {
        const link = mountRow().get(
            '[data-testid="transition-gate-link-tr-1"]',
        );

        expect(link.attributes('href')).toBe(
            '/engine/object-types/deals/edit/transitions/tr-1',
        );
        expect(link.attributes('aria-label')).toContain('Bedingung');
    });

    it('disables the condition link with its reason instead of hiding it', () => {
        const wrapper = mountRow({
            gateHref: null,
            gateLockedReason: 'Ihnen fehlt das Recht.',
        });
        const trigger = wrapper.get(
            '[data-testid="transition-gate-link-tr-1"]',
        );

        expect(trigger.attributes('disabled')).toBe('');
        expect(trigger.attributes('href')).toBeUndefined();
        expect(
            wrapper.get('[data-testid="transition-gate-reason-tr-1"]').text(),
        ).toContain('Ihnen fehlt das Recht.');
    });

    it('offers no condition at all for an implicit transition', () => {
        const wrapper = mountRow({ transitionId: null, gateHref: null });

        expect(
            wrapper.find('[data-testid^="transition-gate-link-"]').exists(),
        ).toBe(false);
        expect(wrapper.find('[data-gate-summary]').exists()).toBe(false);
    });

    it('asks its parent to withdraw the transition', async () => {
        const wrapper = mountRow();

        await wrapper.get('[data-transition-remove]').trigger('click');

        expect(wrapper.emitted('remove')).toHaveLength(1);
    });

    it('disables the withdrawal with its reason instead of hiding it', () => {
        const wrapper = mountRow({
            removeLockedReason:
                'In der Standard-Stage lässt sich immer anlegen.',
        });

        expect(
            wrapper.get('[data-transition-remove]').attributes('disabled'),
        ).toBe('');
        expect(wrapper.get('[data-transition-remove-reason]').text()).toContain(
            'In der Standard-Stage',
        );
    });
});
