import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, reactive } from 'vue';
import DashboardsController from '@/actions/App/Http/Controllers/Dashboards/DashboardsController';
import Form from '@/pages/dashboards/Form.vue';
import type { DashboardRow } from '@/types/dashboards';

interface FormState {
    errors: Record<string, string>;
    processing: boolean;
}

interface SubmitOptions {
    onSuccess?: () => void;
}

const { toastSuccess } = vi.hoisted(() => ({ toastSuccess: vi.fn() }));

vi.mock('vue-sonner', () => ({
    toast: {
        success: (...args: unknown[]) => toastSuccess(...args),
        error: vi.fn(),
    },
}));

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
    visit: vi.fn(),
    transform: {
        apply: null as
            | null
            | ((data: Record<string, unknown>) => Record<string, unknown>),
    },
    form: null as null | FormState,
    page: {
        url: '/nubos/dashboards/create',
        props: {
            auth: {
                user: null,
                can: {} as Record<string, boolean>,
                authority: null as string | null,
            },
        },
    },
}));

vi.mock('@inertiajs/vue3', () => {
    const state = reactive({
        errors: {} as Record<string, string>,
        processing: false,
        transform(
            fn: (data: Record<string, unknown>) => Record<string, unknown>,
        ) {
            inertia.transform.apply = fn;

            return state;
        },
        post: inertia.post,
        put: inertia.put,
    });

    inertia.form = state;

    return {
        Head: { template: '<head-stub><slot /></head-stub>' },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
        useForm: (initial: Record<string, unknown>) =>
            Object.assign(state, initial),
        router: {
            on: vi.fn(() => vi.fn()),
            visit: inertia.visit,
            post: inertia.post,
            put: inertia.put,
        },
        usePage: () => inertia.page,
    };
});

const ENGLISH_WORDS = /\b(the|this|that|you|your|saved|dashboard\s+was)\b/i;

function dashboard(overrides: Partial<DashboardRow> = {}): DashboardRow {
    return {
        id: '01DASHBOARD00000000000001',
        name: 'Vertriebsübersicht',
        description: 'Kennzahlen des laufenden Quartals',
        owner_id: '01USER00000000000000001A',
        is_owner: true,
        is_tenant_wide: false,
        is_default: false,
        can_update: true,
        can_delete: true,
        can_share: true,
        update_reason: null,
        delete_reason: null,
        share_reason: null,
        has_definer_widget: false,
        updated_at: '2026-08-10T14:23:45+02:00',
        ...overrides,
    };
}

function mountCreate(): VueWrapper {
    return mount(Form, {
        props: { mode: 'create', dashboard: null },
    });
}

function mountEdit(overrides: Partial<DashboardRow> = {}): VueWrapper {
    return mount(Form, {
        props: { mode: 'edit', dashboard: dashboard(overrides) },
    });
}

function formState(): FormState {
    if (inertia.form === null) {
        throw new Error('The Inertia form was never created');
    }

    return inertia.form;
}

function isSaveDisabled(wrapper: VueWrapper): boolean {
    return wrapper.get('[data-form-save]').attributes('disabled') !== undefined;
}

function submittedPayload(): Record<string, unknown> {
    const state = formState() as unknown as Record<string, unknown>;
    const carried: Record<string, unknown> = {
        name: state.name,
        description: state.description,
    };
    const apply = inertia.transform.apply;

    return apply === null ? carried : apply(carried);
}

function submitOptions(call: [string, SubmitOptions?]): SubmitOptions {
    const options = call[1];

    if (options === undefined) {
        throw new Error('The form submitted without any visit options');
    }

    return options;
}

beforeEach(() => {
    inertia.post.mockReset();
    inertia.put.mockReset();
    inertia.visit.mockReset();
    inertia.transform.apply = null;
    inertia.page.props.auth.authority = null;
    toastSuccess.mockReset();
    formState().errors = {};
    formState().processing = false;
});

describe('dashboards/Form — the action row', () => {
    it('submits through the shared save button labelled Speichern while creating', () => {
        const wrapper = mountCreate();

        expect(wrapper.get('[data-form-save]').text()).toBe('Speichern');
        expect(wrapper.find('[data-form-actions]').exists()).toBe(true);
        expect(wrapper.find('[data-create-button]').exists()).toBe(false);
    });

    it('keeps the very same label while editing', () => {
        const wrapper = mountEdit();

        expect(wrapper.get('[data-form-save]').text()).toBe('Speichern');
        expect(wrapper.find('[data-create-button]').exists()).toBe(false);
    });

    it('places cancel before save in the right aligned action row', () => {
        const buttons = mountCreate()
            .get('[data-form-actions]')
            .findAll('button');

        expect(buttons.length).toBeGreaterThanOrEqual(2);
        expect(buttons[0].attributes('data-form-cancel')).toBeDefined();
        expect(
            buttons[buttons.length - 1].attributes('data-form-save'),
        ).toBeDefined();
    });

    it('renders no page level back link', () => {
        expect(mountCreate().findAll('a')).toHaveLength(0);
    });
});

describe('dashboards/Form — fields', () => {
    it('binds a label to every control and renders both fields while creating', () => {
        const wrapper = mountCreate();

        expect(wrapper.find('label[for="dashboard-name"]').exists()).toBe(true);
        expect(wrapper.find('#dashboard-name').exists()).toBe(true);
        expect(
            wrapper.find('label[for="dashboard-description"]').exists(),
        ).toBe(true);
        expect(wrapper.find('#dashboard-description').exists()).toBe(true);
    });

    it('starts empty while creating', () => {
        const wrapper = mountCreate();

        expect(
            (wrapper.get('#dashboard-name').element as HTMLInputElement).value,
        ).toBe('');
        expect(
            (
                wrapper.get('#dashboard-description')
                    .element as HTMLTextAreaElement
            ).value,
        ).toBe('');
    });

    it('prefills both controls from the dashboard prop while editing', () => {
        const wrapper = mountEdit();

        expect(
            (wrapper.get('#dashboard-name').element as HTMLInputElement).value,
        ).toBe(dashboard().name);
        expect(
            (
                wrapper.get('#dashboard-description')
                    .element as HTMLTextAreaElement
            ).value,
        ).toBe(dashboard().description);
    });

    it('survives a dashboard whose description the server left empty', () => {
        const wrapper = mountEdit({ description: null });

        expect(
            (
                wrapper.get('#dashboard-description')
                    .element as HTMLTextAreaElement
            ).value,
        ).toBe('');
        expect(isSaveDisabled(wrapper)).toBe(true);
    });

    it('carries no tenant wide switch, which lives on the detail page', () => {
        const wrapper = mountEdit();

        expect(wrapper.find('#dashboard-name').exists()).toBe(true);
        expect(wrapper.find('[data-tenant-wide-toggle]').exists()).toBe(false);
    });
});

describe('dashboards/Form — unsaved changes', () => {
    it('keeps saving disabled until something actually changed', () => {
        expect(isSaveDisabled(mountCreate())).toBe(true);
        expect(isSaveDisabled(mountEdit())).toBe(true);
    });

    it('counts a change of the name as a change', async () => {
        const wrapper = mountEdit();

        await wrapper.get('#dashboard-name').setValue('Vertrieb 2027');

        expect(isSaveDisabled(wrapper)).toBe(false);
    });

    it('counts a change of the description as a change', async () => {
        const wrapper = mountEdit();

        await wrapper.get('#dashboard-description').setValue('Neuer Zweck');

        expect(isSaveDisabled(wrapper)).toBe(false);
    });

    it('asks before leaving with pending changes', async () => {
        const wrapper = mountEdit();

        await wrapper.get('#dashboard-name').setValue('Vertrieb 2027');

        expect(document.body.textContent).not.toContain(
            'Änderungen verwerfen?',
        );

        await wrapper.get('[data-form-cancel]').trigger('click');
        await nextTick();

        expect(document.body.textContent).toContain('Änderungen verwerfen?');
        expect(inertia.visit).not.toHaveBeenCalled();
    });

    it('returns to the list right away while nothing changed', async () => {
        const wrapper = mountEdit();

        await wrapper.get('[data-form-cancel]').trigger('click');
        await nextTick();

        expect(inertia.visit).toHaveBeenCalledTimes(1);
        expect(inertia.visit.mock.calls[0][0]).toBe(
            DashboardsController.index.url(),
        );
    });
});

describe('dashboards/Form — saving', () => {
    it('posts the entered name and description to the store route while creating', async () => {
        const wrapper = mountCreate();

        await wrapper.get('#dashboard-name').setValue('Neues Dashboard');
        await wrapper.get('#dashboard-description').setValue('Erster Wurf');
        await wrapper.get('form').trigger('submit');

        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(inertia.put).not.toHaveBeenCalled();
        expect(inertia.post.mock.calls[0][0]).toBe(
            DashboardsController.store.url(),
        );
        expect(submittedPayload().name).toBe('Neues Dashboard');
        expect(submittedPayload().description).toBe('Erster Wurf');
    });

    it('puts to the update route of that very dashboard while editing', async () => {
        const wrapper = mountEdit();

        await wrapper.get('#dashboard-name').setValue('Vertrieb 2027');
        await wrapper.get('form').trigger('submit');

        expect(inertia.put).toHaveBeenCalledTimes(1);
        expect(inertia.post).not.toHaveBeenCalled();
        expect(inertia.put.mock.calls[0][0]).toBe(
            DashboardsController.update.url({ dashboard: dashboard().id }),
        );
        expect(submittedPayload().name).toBe('Vertrieb 2027');
        expect(submittedPayload().description).toBe(dashboard().description);
    });

    it('sends a cleared description as null instead of empty text', async () => {
        const wrapper = mountEdit();

        await wrapper.get('#dashboard-description').setValue('');
        await wrapper.get('form').trigger('submit');

        expect(submittedPayload().description).toBeNull();

        await wrapper.get('#dashboard-description').setValue('   ');
        await wrapper.get('form').trigger('submit');

        expect(submittedPayload().description).toBeNull();
        expect(submittedPayload().name).toBe(dashboard().name);
    });

    it('settles the form again once the server confirmed the save', async () => {
        const wrapper = mountEdit();

        await wrapper.get('#dashboard-name').setValue('Vertrieb 2027');
        await wrapper.get('form').trigger('submit');

        expect(isSaveDisabled(wrapper)).toBe(false);

        const options = submitOptions(
            inertia.put.mock.calls[0] as [string, SubmitOptions?],
        );

        expect(typeof options.onSuccess).toBe('function');

        options.onSuccess?.();
        await nextTick();

        expect(isSaveDisabled(wrapper)).toBe(true);
    });

    it('reports the successful save as a German toast', async () => {
        const wrapper = mountEdit();

        await wrapper.get('#dashboard-name').setValue('Vertrieb 2027');
        await wrapper.get('form').trigger('submit');

        submitOptions(
            inertia.put.mock.calls[0] as [string, SubmitOptions?],
        ).onSuccess?.();

        expect(toastSuccess).toHaveBeenCalledTimes(1);

        const message = String(toastSuccess.mock.calls[0][0]);

        expect(message.length).toBeGreaterThan(10);
        expect(message).not.toMatch(ENGLISH_WORDS);
    });
});

describe('dashboards/Form — server side errors', () => {
    it('renders a bound message for every key the write path can reject', async () => {
        const wrapper = mountEdit();

        formState().errors = {
            name: 'FEHLER_NAME',
            description: 'FEHLER_DESCRIPTION',
        };
        await nextTick();

        expect(wrapper.text()).toContain('FEHLER_NAME');
        expect(wrapper.text()).toContain('FEHLER_DESCRIPTION');
    });
});
