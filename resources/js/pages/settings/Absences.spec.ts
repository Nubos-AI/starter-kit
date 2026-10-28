import { mount } from '@vue/test-utils';
import type { ColDef } from 'ag-grid-community';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Component } from 'vue';
import { nextTick } from 'vue';
import AbsencesController from '@/actions/App/Http/Controllers/Settings/AbsencesController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import { Checkbox } from '@/components/ui/checkbox';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import Absences from '@/pages/settings/Absences.vue';
import { comboboxAt, comboboxStubs } from '@/tests/selectStubs';
import type { RowAction } from '@/types/rowAction';

const { deleteMock, postMock, visitMock, onMock, formErrors, pageState } =
    vi.hoisted(() => ({
        deleteMock: vi.fn(),
        postMock: vi.fn(),
        visitMock: vi.fn(),
        onMock: vi.fn((event: string, callback: unknown) => {
            void event;
            void callback;

            return (): void => {};
        }),
        formErrors: { value: {} as Record<string, string> },
        pageState: {
            url: '/settings/absences',
            props: {
                auth: {
                    user: null,
                    can: {} as Record<string, boolean>,
                    authority: null,
                },
            },
        },
    }));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    Form: {
        name: 'InertiaFormStub',
        props: ['transform', 'onSuccess'],
        computed: {
            errors: () => formErrors.value,
        },
        template: '<form><slot :errors="errors" :processing="false" /></form>',
    },
    router: {
        on: onMock,
        visit: visitMock,
        post: postMock,
        delete: deleteMock,
    },
    usePage: () => pageState,
}));

type Wrapper = ReturnType<typeof mount>;

interface DelegationRow {
    id: string;
    delegateId: string;
    delegateName: string;
    startsAt: string;
    endsAt: string;
    can_update: boolean;
    can_delete: boolean;
    delete_reason: string | null;
}

const SUBJECT = { id: 'user-1', name: 'Alex Bauer' };

const DELEGATE_OPTIONS = [
    { value: 'user-2', label: 'Robin Fischer' },
    { value: 'user-3', label: 'Kim Neumann' },
];

const MANAGEABLE_OPTIONS = [
    { value: 'user-1', label: 'Alex Bauer' },
    { value: 'user-2', label: 'Robin Fischer' },
];

const passthrough = { template: '<div><slot /></div>' };

const stubs = {
    ...comboboxStubs,
    Dialog: passthrough,
    DialogContent: passthrough,
    DialogHeader: passthrough,
    DialogFooter: passthrough,
    DialogTitle: passthrough,
    DialogDescription: passthrough,
};

function row(overrides: Partial<DelegationRow> = {}): DelegationRow {
    return {
        id: '01ABSENCE0000000000000001',
        delegateId: 'user-2',
        delegateName: 'Robin Fischer',
        startsAt: '2026-09-01',
        endsAt: '2026-09-08',
        can_update: true,
        can_delete: true,
        delete_reason: null,
        ...overrides,
    };
}

const mounted: Wrapper[] = [];

function track(wrapper: Wrapper): Wrapper {
    mounted.push(wrapper);

    return wrapper;
}

function mountPage(overrides: Record<string, unknown> = {}): Wrapper {
    return track(
        mount(Absences, {
            props: {
                subject: SUBJECT,
                isOwnSubject: true,
                canManage: false,
                delegations: [row()],
                delegateOptions: DELEGATE_OPTIONS,
                manageableUserOptions: [],
                ...overrides,
            },
            global: { stubs },
        }),
    );
}

function columns(wrapper: Wrapper): ColDef<DelegationRow>[] {
    return (
        wrapper.findComponent(DataGrid) as unknown as {
            props(key: 'columnDefs'): ColDef<DelegationRow>[];
        }
    ).props('columnDefs');
}

function action(wrapper: Wrapper, label: string): RowAction<DelegationRow> {
    const actionsCol = columns(wrapper).find(
        (column) => column.colId === 'actions',
    );

    const actions = actionsCol?.cellRendererParams
        ?.actions as RowAction<DelegationRow>[];

    const found = actions.find((entry) => entry.label === label);

    if (found === undefined) {
        throw new Error(`Action "${label}" not found`);
    }

    return found;
}

interface GuardEvent {
    detail: {
        visit: {
            method: string;
            url: URL;
            only: string[];
            except: string[];
            prefetch: boolean;
        };
    };
}

function guardEvent(target: string): GuardEvent {
    return {
        detail: {
            visit: {
                method: 'get',
                url: new URL(target, 'https://app.test'),
                only: [],
                except: [],
                prefetch: false,
            },
        },
    };
}

function fireLeaveGuard(target: string): boolean | void {
    const registration = onMock.mock.calls.find((call) => call[0] === 'before');

    if (registration === undefined) {
        throw new Error('No "before" guard was registered');
    }

    return (registration[1] as (event: GuardEvent) => boolean | void)(
        guardEvent(target),
    );
}

function subjectValue(wrapper: Wrapper): string {
    return (wrapper.get('#absence-subject').element as HTMLSelectElement).value;
}

beforeEach(() => {
    deleteMock.mockReset();
    postMock.mockReset();
    visitMock.mockReset();
    onMock.mockClear();
    formErrors.value = {};
});

afterEach(() => {
    while (mounted.length > 0) {
        mounted.pop()?.unmount();
    }
});

describe('settings/Absences', () => {
    it('shows the manage-for switch only with the ability', () => {
        const withAbility = mountPage({
            canManage: true,
            manageableUserOptions: MANAGEABLE_OPTIONS,
        });

        expect(withAbility.find('#absence-subject').exists()).toBe(true);
        expect(
            withAbility
                .find('#absence-subject')
                .findAll('option')
                .map((option) => option.attributes('value')),
        ).toEqual(['user-1', 'user-2']);

        expect(mountPage().find('#absence-subject').exists()).toBe(false);
    });

    it('keeps save disabled after taking a row into the form', async () => {
        const wrapper = mountPage();

        action(wrapper, 'Bearbeiten').onClick?.(row());
        await nextTick();
        await nextTick();

        expect(
            (wrapper.get('#absence-starts-at').element as HTMLInputElement)
                .value,
        ).toBe('2026-09-01');
        expect(wrapper.get('[data-form-save]').attributes('disabled')).toBe('');

        await wrapper.get('#absence-ends-at').setValue('2026-09-09');

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('shows the overlap error at the start date field', () => {
        formErrors.value = {
            startsAt: 'The absence period overlaps an existing one.',
        };

        const wrapper = mountPage();

        expect(
            wrapper.get('#absence-starts-at').element.parentElement
                ?.textContent,
        ).toContain('The absence period overlaps an existing one.');
        expect(
            wrapper.get('#absence-ends-at').element.parentElement?.textContent,
        ).not.toContain('The absence period overlaps an existing one.');
    });

    it('picks the delegate from the server options without the subject', () => {
        const wrapper = mountPage();

        expect(wrapper.find('#absence-delegate').exists()).toBe(true);
        expect(
            comboboxAt(wrapper)
                .findAll('option')
                .map((option) => option.attributes('value')),
        ).toEqual(['user-2', 'user-3']);
        expect(wrapper.find('input[name="delegateId"]').exists()).toBe(false);
    });

    it('sends the picked delegate and subject through the transform', async () => {
        const wrapper = mountPage({
            canManage: true,
            manageableUserOptions: MANAGEABLE_OPTIONS,
        });

        await wrapper.get('#absence-delegate').setValue('user-3');

        const transform = wrapper
            .findComponent({ name: 'InertiaFormStub' })
            .props('transform') as (
            data: Record<string, unknown>,
        ) => Record<string, unknown>;

        expect(
            transform({ startsAt: '2026-09-01', endsAt: '2026-09-08' }),
        ).toMatchObject({
            startsAt: '2026-09-01',
            endsAt: '2026-09-08',
            delegateId: 'user-3',
            userId: SUBJECT.id,
        });
    });

    it('shows the audit hint only for a foreign subject', () => {
        expect(
            mountPage({
                isOwnSubject: false,
                canManage: true,
                manageableUserOptions: MANAGEABLE_OPTIONS,
                subject: { id: 'user-2', name: 'Robin Fischer' },
            })
                .find('[data-testid="absence-audit-hint"]')
                .exists(),
        ).toBe(true);

        expect(
            mountPage().find('[data-testid="absence-audit-hint"]').exists(),
        ).toBe(false);
    });

    it('asks before deleting a single period', async () => {
        const wrapper = mountPage();

        action(wrapper, 'Löschen').onClick?.(row());
        await nextTick();

        expect(wrapper.findComponent(ConfirmDialog).props('open')).toBe(true);
        expect(deleteMock).not.toHaveBeenCalled();
    });

    it('sends the selected ids together with the subject on a bulk delete', async () => {
        const wrapper = mountPage();

        const cell = track(
            mount(columns(wrapper)[0].cellRenderer as Component, {
                props: { params: { data: row() } },
            }),
        );

        cell.findComponent(Checkbox).vm.$emit('update:modelValue', true);
        await nextTick();

        await wrapper.find('[data-testid="bulk-delete"]').trigger('click');
        await wrapper
            .find('[data-testid="bulk-delete-confirm"]')
            .trigger('click');

        expect(postMock).toHaveBeenCalledWith(
            AbsencesController.bulkDestroy.url(),
            { ids: [row().id], userId: SUBJECT.id },
            expect.anything(),
        );
    });

    it('hides the grid without any absence period', () => {
        const wrapper = mountPage({ delegations: [] });

        expect(wrapper.findComponent(DataGrid).exists()).toBe(false);
        expect(wrapper.find('[data-testid="bulk-delete"]').exists()).toBe(
            false,
        );
        expect(wrapper.find('#absence-delegate').exists()).toBe(true);
    });

    it('navigates to the picked subject', async () => {
        const wrapper = mountPage({
            canManage: true,
            manageableUserOptions: MANAGEABLE_OPTIONS,
        });

        await wrapper.get('#absence-subject').setValue('user-2');

        expect(visitMock).toHaveBeenCalledWith(
            AbsencesController.index.url({ query: { user: 'user-2' } }),
        );
    });

    it('asks before leaving with pending changes', async () => {
        const wrapper = mountPage();

        await wrapper.get('#absence-ends-at').setValue('2026-09-30');

        expect(fireLeaveGuard('/settings/profile')).toBe(false);

        await nextTick();

        const dialog = wrapper.findComponent(UnsavedChangesDialog);

        expect(dialog.props('open')).toBe(true);
        expect(dialog.text()).toContain('Änderungen verwerfen?');
        expect(visitMock).not.toHaveBeenCalled();
    });

    it('keeps the subject picker in sync when the leave prompt is cancelled', async () => {
        const wrapper = mountPage({
            canManage: true,
            manageableUserOptions: MANAGEABLE_OPTIONS,
        });

        await wrapper.get('#absence-ends-at').setValue('2026-09-30');
        await wrapper.get('#absence-subject').setValue('user-2');

        const target = AbsencesController.index.url({
            query: { user: 'user-2' },
        });

        expect(visitMock).toHaveBeenCalledWith(target);
        expect(fireLeaveGuard(target)).toBe(false);

        await nextTick();

        const dialog = wrapper.findComponent(UnsavedChangesDialog);

        expect(dialog.props('open')).toBe(true);

        const stay = dialog
            .findAll('button')
            .find((button) => button.text() === 'Nein, hier bleiben');

        await stay!.trigger('click');

        expect(dialog.props('open')).toBe(false);
        expect(subjectValue(wrapper)).toBe(SUBJECT.id);

        visitMock.mockClear();

        await wrapper.get('#absence-subject').setValue('user-2');

        expect(visitMock).toHaveBeenCalledWith(target);
    });
});
