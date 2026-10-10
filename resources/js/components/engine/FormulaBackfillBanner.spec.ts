import type { DOMWrapper, VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { nextTick, ref } from 'vue';
import englishMessages from '@/../lang/en/i18n.json';
import FormulaBackfillBanner from '@/components/engine/FormulaBackfillBanner.vue';
import type { FormulaBackfillRunState } from '@/composables/useFormulaBackfill';
import { useFormulaBackfill } from '@/composables/useFormulaBackfill';
import { translationCatalogueKey } from '@/lib/i18n';
import type { BackfillStatus } from '@/types/formulas';

const OBJECT_TYPE = 'contacts';

const RUN_ID = '01JQZ3F5H8K2M4N6P8R0S2T4V6';

vi.mock('@/composables/useFormulaBackfill', () => ({
    useFormulaBackfill: vi.fn(),
}));

const cancel = vi.fn(() => Promise.resolve());

function runState(
    overrides: Partial<FormulaBackfillRunState> = {},
): FormulaBackfillRunState {
    return {
        id: RUN_ID,
        status: 'running',
        totalCount: 120,
        processedCount: 40,
        errorCount: 0,
        ...overrides,
    };
}

function stubComposable(
    run: FormulaBackfillRunState | null,
    isCancelling = false,
): void {
    vi.mocked(useFormulaBackfill).mockReturnValue({
        run: ref(run),
        isCancelling: ref(isCancelling),
        cancel,
    });
}

function mountBanner(): VueWrapper {
    return mount(FormulaBackfillBanner, {
        props: { objectTypeSlug: OBJECT_TYPE },
        global: {
            provide: {
                [translationCatalogueKey as symbol]: () => ({
                    locale: 'en',
                    fallbackLocale: 'en',
                    messages: { i18n: englishMessages },
                }),
            },
        },
    });
}

function cancelButton(
    wrapper: VueWrapper,
): DOMWrapper<HTMLButtonElement> | undefined {
    return wrapper
        .findAll('button')
        .find((button) => /cancel/i.test(button.text()));
}

function dismissButton(
    wrapper: VueWrapper,
): DOMWrapper<HTMLButtonElement> | undefined {
    return wrapper
        .findAll('button')
        .find((button) => /dismiss/i.test(button.text()));
}

afterEach(() => {
    vi.clearAllMocks();
});

describe('FormulaBackfillBanner — a run in flight', () => {
    it('reads the backfill state for the object type it is mounted for', () => {
        stubComposable(runState());

        mountBanner();

        expect(vi.mocked(useFormulaBackfill).mock.calls[0][0]).toBe(
            OBJECT_TYPE,
        );
    });

    it.each(['pending', 'running'] as BackfillStatus[])(
        'shows the progress and a cancel control while the run is %s',
        (status) => {
            stubComposable(runState({ status }));

            const wrapper = mountBanner();

            expect(wrapper.text()).toContain('40');
            expect(wrapper.text()).toContain('120');
            expect(cancelButton(wrapper)).toBeDefined();
        },
    );

    it('asks the composable to cancel when the control is used', async () => {
        stubComposable(runState());

        const wrapper = mountBanner();
        const button = cancelButton(wrapper);

        expect(button).toBeDefined();

        await button!.trigger('click');

        expect(cancel).toHaveBeenCalledTimes(1);
    });

    it('locks the cancel control while a cancellation is in flight', () => {
        stubComposable(runState(), true);

        const wrapper = mountBanner();
        const button = cancelButton(wrapper);

        expect(button).toBeDefined();
        expect(button!.attributes('disabled')).toBeDefined();
    });
});

describe('FormulaBackfillBanner — a run that ended', () => {
    it('names the number of failed records instead of reporting a plain success', () => {
        stubComposable(
            runState({
                status: 'completed',
                processedCount: 120,
                errorCount: 3,
            }),
        );

        const wrapper = mountBanner();

        expect(wrapper.text().trim()).not.toBe('');
        expect(wrapper.text()).toContain('3');
        expect(wrapper.text()).toMatch(/error/i);
    });

    it('reports a failed run like one that ended with errors, without inventing a reason', () => {
        stubComposable(
            runState({
                status: 'failed',
                processedCount: 90,
                errorCount: 7,
            }),
        );

        const wrapper = mountBanner();

        expect(wrapper.text().trim()).not.toBe('');
        expect(wrapper.text()).toContain('7');
        expect(wrapper.text()).toMatch(/error|failed/i);
        expect(wrapper.text()).not.toContain('undefined');
        expect(wrapper.text()).not.toContain('null');
    });

    it('shows a cancelled run with the progress frozen at the point it stopped', () => {
        stubComposable(
            runState({
                status: 'cancelled',
                processedCount: 40,
            }),
        );

        const wrapper = mountBanner();

        expect(wrapper.text()).toMatch(/cancel/i);
        expect(wrapper.text()).toContain('40');
        expect(wrapper.text()).toContain('120');
    });

    it('offers a way to dismiss a run that ended', () => {
        stubComposable(runState({ status: 'cancelled' }));

        const wrapper = mountBanner();

        expect(dismissButton(wrapper)).toBeDefined();
    });

    it('hides the notice once it is dismissed', async () => {
        stubComposable(runState({ status: 'cancelled' }));

        const wrapper = mountBanner();
        const button = dismissButton(wrapper);

        expect(button).toBeDefined();

        await button!.trigger('click');
        await nextTick();

        expect(wrapper.text().trim()).toBe('');
        expect(wrapper.findAll('button')).toHaveLength(0);
    });

    it('says nothing about a run that completed without a single error', () => {
        stubComposable(
            runState({
                status: 'completed',
                processedCount: 120,
                errorCount: 0,
            }),
        );

        const wrapper = mountBanner();

        expect(wrapper.text().trim()).toBe('');
        expect(wrapper.findAll('button')).toHaveLength(0);
    });
});

describe('FormulaBackfillBanner — nothing worth announcing', () => {
    it('renders nothing when no run exists for the object type', () => {
        stubComposable(null);

        const wrapper = mountBanner();

        expect(wrapper.text().trim()).toBe('');
        expect(wrapper.findAll('button')).toHaveLength(0);
    });

    it.each([
        'pending',
        'running',
        'completed',
        'cancelled',
        'failed',
    ] as BackfillStatus[])(
        'raises no standing notice for a %s run over zero records',
        (status) => {
            stubComposable(
                runState({
                    status,
                    totalCount: 0,
                    processedCount: 0,
                    errorCount: 0,
                }),
            );

            const wrapper = mountBanner();

            expect(wrapper.text().trim()).toBe('');
            expect(wrapper.findAll('button')).toHaveLength(0);
        },
    );
});

describe('FormulaBackfillBanner — completion signal', () => {
    it('emits completed so the grid can refresh once the run finishes', async () => {
        stubComposable(runState());

        const wrapper = mountBanner();
        const options = vi.mocked(useFormulaBackfill).mock.calls[0][1];

        expect(options?.onCompleted).toBeInstanceOf(Function);

        options?.onCompleted?.();
        await nextTick();

        expect(wrapper.emitted('completed')).toHaveLength(1);
    });
});
