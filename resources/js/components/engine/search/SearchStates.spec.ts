import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { axe } from 'vitest-axe';
import SearchStates from '@/components/engine/search/SearchStates.vue';
import SegmentStates from '@/components/engine/segment/SegmentStates.vue';

const axeOptions = {
    runOnly: {
        type: 'tag' as const,
        values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'],
    },
    rules: { 'color-contrast': { enabled: false } },
};

function buttonByText(wrapper: ReturnType<typeof mount>, text: string) {
    return wrapper.findAll('button').find((b) => b.text().includes(text));
}

describe('SearchStates — empty state', () => {
    it('renders the no-match copy without a call to action', () => {
        const wrapper = mount(SearchStates, {
            props: { state: 'empty', emptyKind: 'no-match' },
        });

        expect(wrapper.text()).toContain('Keine Treffer');
        expect(wrapper.findAll('button')).toHaveLength(0);
    });
});

describe('SearchStates — error state', () => {
    it('renders an alert with the message and emits retry once on click', async () => {
        const wrapper = mount(SearchStates, {
            props: {
                state: 'error',
                errorMessage: 'Zeitüberschreitung bei der Suche.',
            },
        });

        const alert = wrapper.find('[role="alert"]');
        expect(alert.exists()).toBe(true);
        expect(wrapper.text()).toContain('Suche fehlgeschlagen');
        expect(wrapper.text()).toContain('Zeitüberschreitung bei der Suche.');

        const retry = buttonByText(wrapper, 'Erneut versuchen');
        expect(retry).toBeDefined();
        await retry!.trigger('click');
        expect(wrapper.emitted('retry')).toHaveLength(1);
    });
});

describe('SearchStates — loading state', () => {
    it('renders an aria-hidden skeleton and no alert', () => {
        const wrapper = mount(SearchStates, { props: { state: 'loading' } });

        expect(wrapper.find('[aria-hidden="true"]').exists()).toBe(true);
        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
    });
});

describe('SearchStates — ready state', () => {
    it('renders nothing once ready', () => {
        const wrapper = mount(SearchStates, { props: { state: 'ready' } });
        expect(wrapper.find('div').exists()).toBe(false);
    });
});

describe('SearchStates — accessibility (WCAG 2.1 AA)', () => {
    it('has no axe violations in the error state', async () => {
        const wrapper = mount(SearchStates, {
            props: { state: 'error', errorMessage: 'Fehler.' },
        });
        const results = await axe(wrapper.element, axeOptions);
        expect(results.violations).toEqual([]);
    });

    it('has no axe violations in the empty state', async () => {
        const wrapper = mount(SearchStates, {
            props: { state: 'empty', emptyKind: 'no-match' },
        });
        const results = await axe(wrapper.element, axeOptions);
        expect(results.violations).toEqual([]);
    });
});

describe('SegmentStates — empty states', () => {
    it('offers a create CTA for the no-segments empty state and emits create once', async () => {
        const wrapper = mount(SegmentStates, {
            props: { state: 'empty', emptyKind: 'no-segments' },
        });

        expect(wrapper.text()).toContain('Noch keine Segmente');
        const create = buttonByText(wrapper, 'Segment anlegen');
        expect(create).toBeDefined();
        await create!.trigger('click');
        expect(wrapper.emitted('create')).toHaveLength(1);
    });

    it('renders the empty-static copy without a call to action', () => {
        const wrapper = mount(SegmentStates, {
            props: { state: 'empty', emptyKind: 'empty-static' },
        });

        expect(wrapper.text()).toContain('Leere statische Liste');
        expect(wrapper.findAll('button')).toHaveLength(0);
    });
});

describe('SegmentStates — error state', () => {
    it('renders an alert and emits retry once on click', async () => {
        const wrapper = mount(SegmentStates, {
            props: { state: 'error' },
        });

        const alert = wrapper.find('[role="alert"]');
        expect(alert.exists()).toBe(true);
        expect(wrapper.text()).toContain(
            'Segmente konnten nicht geladen werden',
        );

        const retry = buttonByText(wrapper, 'Erneut versuchen');
        expect(retry).toBeDefined();
        await retry!.trigger('click');
        expect(wrapper.emitted('retry')).toHaveLength(1);
    });
});

describe('SegmentStates — accessibility (WCAG 2.1 AA)', () => {
    it('has no axe violations in the error state', async () => {
        const wrapper = mount(SegmentStates, { props: { state: 'error' } });
        const results = await axe(wrapper.element, axeOptions);
        expect(results.violations).toEqual([]);
    });

    it('has no axe violations in the no-segments empty state', async () => {
        const wrapper = mount(SegmentStates, {
            props: { state: 'empty', emptyKind: 'no-segments' },
        });
        const results = await axe(wrapper.element, axeOptions);
        expect(results.violations).toEqual([]);
    });
});
