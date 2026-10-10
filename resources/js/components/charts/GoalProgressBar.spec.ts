import type { DOMWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import GoalProgressBar from '@/components/charts/GoalProgressBar.vue';

type Wrapper = ReturnType<typeof mount>;

function mountBar(props: {
    label?: string;
    current: number;
    target: number;
    direction: 'at_least' | 'at_most';
}): Wrapper {
    return mount(GoalProgressBar, {
        props: {
            label: 'Abschlüsse im Quartal',
            ...props,
        },
    });
}

function trackOf(wrapper: Wrapper): DOMWrapper<Element> {
    return wrapper.find('[data-goal-progress]');
}

function barOf(wrapper: Wrapper): DOMWrapper<Element> {
    return wrapper.find('[data-goal-bar]');
}

describe('GoalProgressBar towards a goal', () => {
    it('runs on the primary colour while the goal is still ahead', () => {
        const wrapper = mountBar({
            current: 50,
            target: 100,
            direction: 'at_least',
        });

        expect(trackOf(wrapper).attributes('data-goal-state')).toBe(
            'in_progress',
        );
        expect(trackOf(wrapper).attributes('aria-valuenow')).toBe('50');
        expect(barOf(wrapper).classes()).toContain('bg-primary');
        expect(barOf(wrapper).attributes('style')).toBe('width: 50%;');
    });

    it('turns to the success intent and clamps the bar once the goal is met', () => {
        const wrapper = mountBar({
            current: 120,
            target: 100,
            direction: 'at_least',
        });

        expect(trackOf(wrapper).attributes('data-goal-state')).toBe('reached');
        expect(trackOf(wrapper).attributes('aria-valuenow')).toBe('100');
        expect(trackOf(wrapper).attributes('aria-valuetext')).toContain('120');
        expect(barOf(wrapper).classes()).toContain('bg-success-bold');
        expect(wrapper.text()).toContain('Erreicht');
    });
});

describe('GoalProgressBar under a ceiling', () => {
    it('stays calm well below the ceiling', () => {
        const wrapper = mountBar({
            current: 50,
            target: 100,
            direction: 'at_most',
        });

        expect(trackOf(wrapper).attributes('data-goal-state')).toBe('within');
        expect(barOf(wrapper).classes()).toContain('bg-primary');
    });

    it('warns as soon as the ceiling comes close', () => {
        const wrapper = mountBar({
            current: 95,
            target: 100,
            direction: 'at_most',
        });

        expect(trackOf(wrapper).attributes('data-goal-state')).toBe('at_risk');
        expect(barOf(wrapper).classes()).toContain('bg-warning-bold');
    });

    it('flips over once the ceiling is broken', () => {
        const wrapper = mountBar({
            current: 130,
            target: 100,
            direction: 'at_most',
        });

        expect(trackOf(wrapper).attributes('data-goal-state')).toBe('exceeded');
        expect(trackOf(wrapper).attributes('aria-valuenow')).toBe('100');
        expect(trackOf(wrapper).attributes('aria-valuetext')).toContain('130');
        expect(barOf(wrapper).classes()).toContain('bg-destructive');
    });

    it('tells the two directions apart at the very same numbers', () => {
        const atLeast = mountBar({
            current: 130,
            target: 100,
            direction: 'at_least',
        });
        const atMost = mountBar({
            current: 130,
            target: 100,
            direction: 'at_most',
        });

        expect(trackOf(atLeast).attributes('data-goal-state')).not.toBe(
            trackOf(atMost).attributes('data-goal-state'),
        );
        expect(atLeast.text()).not.toBe(atMost.text());
    });
});

describe('GoalProgressBar edge cases', () => {
    it('gives up on a goal of zero instead of dividing by it', () => {
        const wrapper = mountBar({
            current: 5,
            target: 0,
            direction: 'at_least',
        });

        expect(trackOf(wrapper).attributes('data-goal-state')).toBe('unknown');
        expect(trackOf(wrapper).attributes('aria-valuenow')).toBe('0');
        expect(barOf(wrapper).attributes('style')).toBe('width: 0%;');
        expect(wrapper.html()).not.toContain('NaN');
    });

    it('gives up on a negative goal', () => {
        const wrapper = mountBar({
            current: 5,
            target: -5,
            direction: 'at_most',
        });

        expect(trackOf(wrapper).attributes('data-goal-state')).toBe('unknown');
        expect(wrapper.html()).not.toContain('NaN');
    });

    it('gives up on a goal that is not a finite number', () => {
        const wrapper = mountBar({
            current: 5,
            target: Number.POSITIVE_INFINITY,
            direction: 'at_least',
        });

        expect(trackOf(wrapper).attributes('data-goal-state')).toBe('unknown');
        expect(wrapper.html()).not.toContain('NaN');
    });

    it('never prints NaN when the current value is not a number', () => {
        const wrapper = mountBar({
            current: Number.NaN,
            target: 100,
            direction: 'at_least',
        });

        expect(wrapper.html()).not.toContain('NaN');
        expect(trackOf(wrapper).attributes('aria-valuenow')).toMatch(
            /^\d+(\.\d+)?$/,
        );
    });

    it('clamps a negative current value to the start of the bar', () => {
        const wrapper = mountBar({
            current: -10,
            target: 100,
            direction: 'at_least',
        });

        expect(trackOf(wrapper).attributes('aria-valuenow')).toBe('0');
        expect(barOf(wrapper).attributes('style')).toBe('width: 0%;');
    });
});

describe('GoalProgressBar accessibility and colour', () => {
    it('announces itself as a progress bar on a scale of nought to a hundred', () => {
        const wrapper = mountBar({
            current: 25,
            target: 100,
            direction: 'at_least',
        });
        const track = trackOf(wrapper);

        expect(track.attributes('role')).toBe('progressbar');
        expect(track.attributes('aria-valuemin')).toBe('0');
        expect(track.attributes('aria-valuemax')).toBe('100');
        expect(track.attributes('aria-label')).toBe('Abschlüsse im Quartal');
        expect(wrapper.text()).toContain('Abschlüsse im Quartal');
    });

    it('paints the fill through tokens and carries no fixed colour value', () => {
        const wrapper = mountBar({
            current: 25,
            target: 100,
            direction: 'at_least',
        });

        expect(wrapper.html()).not.toMatch(/#[0-9a-fA-F]{3,8}|rgba?\(/);
        expect(barOf(wrapper).attributes('style')).toMatch(
            /^width:\s*\d+(\.\d+)?%;?$/,
        );
    });

    it('spells every state out in its own German words', () => {
        const states = [
            mountBar({ current: 50, target: 100, direction: 'at_least' }),
            mountBar({ current: 120, target: 100, direction: 'at_least' }),
            mountBar({ current: 50, target: 100, direction: 'at_most' }),
            mountBar({ current: 95, target: 100, direction: 'at_most' }),
            mountBar({ current: 130, target: 100, direction: 'at_most' }),
        ];
        const texts = states.map((wrapper) =>
            wrapper.find('[data-goal-status]').text(),
        );

        texts.forEach((text) => {
            expect(text.length).toBeGreaterThan(2);
        });

        expect(new Set(texts).size).toBe(states.length);
    });
});

describe('GoalProgressBar reading', () => {
    it('prints the reading against the target instead of hiding it in an aria attribute', () => {
        const wrapper = mountBar({
            current: 4200,
            target: 10000,
            direction: 'at_least',
        });
        const value = wrapper.find('[data-goal-value]');

        expect(value.exists()).toBe(true);
        expect(value.text()).toContain('4.200');
        expect(value.text()).toContain('10.000');
        expect(value.text()).toContain('42');
    });

    it('formats a fractional percentage in German notation', () => {
        const wrapper = mountBar({
            current: 5,
            target: 1000,
            direction: 'at_least',
        });

        expect(wrapper.find('[data-goal-value]').text()).toContain('0,5');
    });

    it('says nothing numeric when there is no usable target', () => {
        const wrapper = mountBar({
            current: 5,
            target: 0,
            direction: 'at_least',
        });

        expect(wrapper.find('[data-goal-value]').text()).not.toMatch(/\d/);
    });
});

describe('GoalProgressBar inline layout', () => {
    it('keeps the label out of the row and leaves it to the accessible name', () => {
        const wrapper = mount(GoalProgressBar, {
            props: {
                label: 'Abschlüsse im Quartal',
                current: 5,
                target: 1000,
                direction: 'at_least',
                layout: 'inline',
            },
        });

        expect(wrapper.text()).not.toContain('Abschlüsse im Quartal');
        expect(trackOf(wrapper).attributes('aria-label')).toBe(
            'Abschlüsse im Quartal',
        );
        expect(wrapper.find('[data-goal-value]').text()).toContain('5');
        expect(wrapper.find('[data-goal-status]').text()).toBe('In Arbeit');
    });

    it('lays the track beside the figures instead of below them', () => {
        const wrapper = mount(GoalProgressBar, {
            props: {
                label: 'Abschlüsse im Quartal',
                current: 5,
                target: 1000,
                direction: 'at_least',
                layout: 'inline',
            },
        });
        const root = wrapper.element as HTMLElement;

        expect(root.className).toContain('items-center');
        expect(root.children).toHaveLength(3);
    });

    it('fills the cell height and reads at the same size as the row beside it', () => {
        const wrapper = mount(GoalProgressBar, {
            props: {
                label: 'Abschlüsse im Quartal',
                current: 5,
                target: 1000,
                direction: 'at_least',
                layout: 'inline',
            },
        });

        expect((wrapper.element as HTMLElement).className).toContain('h-full');
        expect(wrapper.get('[data-goal-value]').classes()).toContain('text-sm');
        expect(wrapper.get('[data-goal-value]').classes()).not.toContain(
            'text-xs',
        );
        expect(wrapper.get('[data-goal-status]').classes()).toContain(
            'text-sm',
        );
    });

    it('leaves the stacked layout at its smaller reading size', () => {
        const wrapper = mountBar({
            current: 5,
            target: 1000,
            direction: 'at_least',
        });

        expect(wrapper.get('[data-goal-value]').classes()).toContain('text-xs');
        expect((wrapper.element as HTMLElement).className).not.toContain(
            'h-full',
        );
    });

    it('still stacks by default', () => {
        const wrapper = mountBar({
            current: 5,
            target: 1000,
            direction: 'at_least',
        });

        expect(wrapper.text()).toContain('Abschlüsse im Quartal');
        expect((wrapper.element as HTMLElement).className).toContain(
            'flex-col',
        );
    });
});
