import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, toValue } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { usePageBreadcrumbs } from '@/composables/useBreadcrumbs';
import Decide from '@/pages/approvals/Decide.vue';
import { comboboxStubs, selectStubs } from '@/tests/selectStubs';

type BeforeHandler = (event: {
    detail: { visit: { method: string; url: string } };
}) => unknown;

const { postMock, visitMock, onMock, beforeHandlers } = vi.hoisted(() => {
    const handlers: unknown[] = [];

    return {
        postMock: vi.fn(),
        visitMock: vi.fn(),
        onMock: vi.fn((event: string, handler: unknown) => {
            if (event === 'before') {
                handlers.push(handler);
            }

            return vi.fn();
        }),
        beforeHandlers: handlers,
    };
});

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: {
        on: onMock,
        visit: visitMock,
        post: postMock,
    },
    usePage: () => ({
        url: '/nubos/engine/approvals/01APPROVALPROCESS000000001',
        props: { auth: { user: null, can: {}, authority: null } },
    }),
}));

vi.mock('@/composables/useBreadcrumbs', () => ({
    usePageBreadcrumbs: vi.fn(),
}));

type Wrapper = ReturnType<typeof mount>;

interface ApprovalHistoryEntry {
    id: string;
    type: string;
    type_label: string;
    actor_label: string;
    on_behalf_of_label: string | null;
    reason: string | null;
    occurred_at: string;
}

interface ApprovalDetail {
    id: string;
    record_id: string | null;
    anchor_label: string;
    anchor_kind_label: string;
    anchor_url: string | null;
    transition_label: string;
    stage_label: string;
    deadline_at: string | null;
    is_overdue: boolean;
    on_behalf_of_id: string | null;
    on_behalf_of_label: string | null;
    history: ApprovalHistoryEntry[];
    can_decide: boolean;
    can_cancel: boolean;
    decision_reason: string | null;
    on_behalf_of_options: Array<{ value: string; label: string }>;
}

function approval(overrides: Partial<ApprovalDetail> = {}): ApprovalDetail {
    return {
        id: '01APPROVALPROCESS000000001',
        record_id: '01RECORD000000000000000001',
        anchor_label: 'Rahmenvertrag 2026',
        anchor_kind_label: 'Verträge',
        anchor_url: 'https://nubos.test/anchor-target',
        transition_label: 'Offen → Genehmigt',
        stage_label: 'Stufe 1',
        deadline_at: '2026-09-05T12:00:00.000Z',
        is_overdue: false,
        on_behalf_of_id: null,
        on_behalf_of_label: null,
        history: [],
        can_decide: true,
        can_cancel: false,
        decision_reason: null,
        on_behalf_of_options: [],
        ...overrides,
    };
}

const mounted: Wrapper[] = [];

function track(wrapper: Wrapper): Wrapper {
    mounted.push(wrapper);

    return wrapper;
}

function mountDecide(overrides: Partial<ApprovalDetail> = {}): Wrapper {
    return track(
        mount(Decide, {
            props: { approval: approval(overrides) },
            global: { stubs: { ...selectStubs, ...comboboxStubs } },
        }),
    );
}

async function chooseDecision(
    wrapper: Wrapper,
    value: 'approved' | 'rejected',
): Promise<void> {
    await wrapper.get('select.ui-select').setValue(value);
    await nextTick();
}

function lastBeforeHandler(): BeforeHandler {
    return beforeHandlers[beforeHandlers.length - 1] as BeforeHandler;
}

beforeEach(() => {
    postMock.mockReset();
    visitMock.mockReset();
    vi.mocked(usePageBreadcrumbs).mockReset();
    beforeHandlers.length = 0;
});

afterEach(() => {
    while (mounted.length > 0) {
        mounted.pop()?.unmount();
    }
});

describe('approvals/Decide', () => {
    it('requires a reason once reject is chosen', async () => {
        const wrapper = mountDecide();

        await chooseDecision(wrapper, 'rejected');
        await wrapper.get('[data-form-save]').trigger('click');
        await nextTick();

        expect(wrapper.findComponent(InputError).props('message')).toBeTruthy();
        expect(postMock).not.toHaveBeenCalled();
    });

    it('does not require a reason for an approval', async () => {
        const wrapper = mountDecide();

        await chooseDecision(wrapper, 'approved');
        await wrapper.get('[data-form-save]').trigger('click');
        await nextTick();

        expect(postMock).toHaveBeenCalledTimes(1);

        const [, payload] = postMock.mock.calls[0];

        expect(payload).toMatchObject({ decision: 'approved' });
    });

    it('disables the decision controls with a stated reason', () => {
        const wrapper = mountDecide({
            can_decide: false,
            decision_reason: 'Sie sind von dieser Stufe ausgeschlossen.',
        });

        expect(wrapper.get('fieldset').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain(
            'Sie sind von dieser Stufe ausgeschlossen.',
        );
    });

    it('links the anchor label to the target the server provides', () => {
        const wrapper = mountDecide({
            record_id: null,
            anchor_label: 'Promotion vom 10.09.2026 09:00 UTC',
            anchor_kind_label: 'Promotion',
            anchor_url: 'https://nubos.test/promotion-target',
        });

        const anchor = wrapper.get('[data-approval-anchor]');

        expect(anchor.element.tagName).toBe('A');
        expect(anchor.attributes('href')).toBe(
            'https://nubos.test/promotion-target',
        );
        expect(anchor.text()).toBe('Promotion vom 10.09.2026 09:00 UTC');
    });

    it('shows the anchor label as plain text when the anchor has no target of its own', () => {
        const wrapper = mountDecide({
            record_id: null,
            anchor_label: 'Promotion vom 10.09.2026 09:00 UTC',
            anchor_kind_label: 'Promotion',
            anchor_url: null,
        });

        const anchor = wrapper.get('[data-approval-anchor]');

        expect(anchor.element.tagName).not.toBe('A');
        expect(anchor.attributes('href')).toBeUndefined();
        expect(anchor.text()).toBe('Promotion vom 10.09.2026 09:00 UTC');
        expect(
            wrapper
                .findAll('a')
                .filter(
                    (link) =>
                        link.text() === 'Promotion vom 10.09.2026 09:00 UTC',
                ),
        ).toHaveLength(0);
    });

    it('shows the kind of the anchor', () => {
        const wrapper = mountDecide({ anchor_kind_label: 'Promotion' });

        expect(wrapper.get('[data-approval-anchor-kind]').text()).toBe(
            'Promotion',
        );
    });

    it('names the breadcrumb after the anchor label', () => {
        mountDecide({ anchor_label: 'Promotion vom 10.09.2026 09:00 UTC' });

        const calls = vi.mocked(usePageBreadcrumbs).mock.calls;

        expect(calls).toHaveLength(1);
        expect(toValue(calls[0][0])).toEqual([
            { title: 'Promotion vom 10.09.2026 09:00 UTC' },
        ]);
    });

    it('warns before leaving with an unsaved reason', async () => {
        const wrapper = mountDecide();

        expect(wrapper.get('[data-form-save]').attributes('disabled')).toBe('');

        await wrapper.get('#approval-reason').setValue('Bitte nachbessern.');
        await nextTick();

        const blocked = lastBeforeHandler()({
            detail: { visit: { method: 'get', url: '/nubos/engine' } },
        });

        expect(blocked).toBe(false);
        await nextTick();

        expect(wrapper.findComponent(UnsavedChangesDialog).props('open')).toBe(
            true,
        );
        expect(visitMock).not.toHaveBeenCalled();
    });

    it('asks before the cancel button returns to the list', async () => {
        const wrapper = mountDecide();

        await chooseDecision(wrapper, 'approved');
        await wrapper.get('[data-form-cancel]').trigger('click');
        await nextTick();

        expect(wrapper.findComponent(UnsavedChangesDialog).props('open')).toBe(
            true,
        );
        expect(visitMock).not.toHaveBeenCalled();

        wrapper.findComponent(UnsavedChangesDialog).vm.$emit('confirm');
        await nextTick();

        expect(visitMock).toHaveBeenCalledTimes(1);
    });

    it('offers the cancellation only to someone who may cancel', async () => {
        const withoutRight = mountDecide();

        expect(withoutRight.find('[data-approval-cancel]').exists()).toBe(
            false,
        );

        const wrapper = mountDecide({ can_cancel: true });

        await wrapper.get('[data-approval-cancel]').trigger('click');
        await nextTick();

        wrapper.findComponent(ConfirmDialog).vm.$emit('confirm');
        await nextTick();

        expect(postMock).toHaveBeenCalledTimes(1);

        const [url] = postMock.mock.calls[0];

        expect(url).toContain('/engine/approvals/');
        expect(url).toContain('/cancel');
    });

    it('shows the on-behalf-of combobox only when options are offered', () => {
        const withoutOptions = mountDecide({ on_behalf_of_options: [] });

        expect(withoutOptions.find('select.ui-combobox').exists()).toBe(false);

        const withOptions = mountDecide({
            on_behalf_of_options: [
                { value: 'delegator-1', label: 'Jo Brandt' },
            ],
        });

        expect(withOptions.find('select.ui-combobox').exists()).toBe(true);
    });
});
