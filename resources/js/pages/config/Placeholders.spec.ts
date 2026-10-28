import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import ConfigBundleController from '@/actions/App/Http/Controllers/ConfigBundle/ConfigBundleController';
import FormActions from '@/components/FormActions.vue';
import InputError from '@/components/InputError.vue';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import Placeholders from '@/pages/config/Placeholders.vue';

interface Requirement {
    artifact_key: string;
    label: string;
    current_value: string;
}

interface PlaceholdersProps {
    run: { id: string };
    requirements: Requirement[];
    can_save: boolean;
    save_reason: string | null;
}

const { visitMock, formErrors } = vi.hoisted(() => ({
    visitMock: vi.fn(),
    formErrors: { current: {} as Record<string, string> },
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        url: '/nubos/engine/config/import/01JCONFIGIMPORTRUN0000001/placeholders',
        props: { errors: {} },
    }),
    Head: { template: '<head-stub><slot /></head-stub>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    Form: {
        name: 'InertiaFormStub',
        props: ['transform', 'onSuccess'],
        setup() {
            return { formErrors };
        },
        template:
            '<form><slot :errors="formErrors.current" :processing="false" /></form>',
    },
    router: { on: vi.fn(() => vi.fn()), visit: visitMock },
}));

type Wrapper = ReturnType<typeof mount>;

const runId = '01JCONFIGIMPORTRUN0000001';

const requirements: Requirement[] = [
    {
        artifact_key: 'Deal Sync',
        label: 'Deal Sync',
        current_value: '__PLACEHOLDER__',
    },
    {
        artifact_key: 'Hook v1.2',
        label: 'Hook v1.2',
        current_value: 'https://hooks.example.test/inbound/hook-v12',
    },
];

const mounted: Wrapper[] = [];

function mountPlaceholders(
    overrides: Partial<PlaceholdersProps> = {},
): Wrapper {
    const wrapper = mount(Placeholders, {
        props: {
            run: { id: runId },
            requirements,
            can_save: true,
            save_reason: null,
            ...overrides,
        },
    });

    mounted.push(wrapper);

    return wrapper;
}

function fieldValue(wrapper: Wrapper, selector: string): string {
    const element = wrapper.get(selector).element;

    if (!(element instanceof HTMLInputElement)) {
        throw new Error(`${selector} is not an input`);
    }

    return element.value;
}

beforeEach(() => {
    visitMock.mockReset();
    formErrors.current = {};
});

afterEach(() => {
    while (mounted.length > 0) {
        mounted.pop()?.unmount();
    }
});

describe('config/Placeholders', () => {
    it('renders one target field per requirement, labelled and prefilled with the value from the bundle', () => {
        const wrapper = mountPlaceholders();

        expect(wrapper.findAll('[data-placeholder-field]')).toHaveLength(2);
        expect(fieldValue(wrapper, '#placeholder-target-0')).toBe(
            '__PLACEHOLDER__',
        );
        expect(fieldValue(wrapper, '#placeholder-target-1')).toBe(
            'https://hooks.example.test/inbound/hook-v12',
        );
        expect(
            wrapper.get('label[for="placeholder-target-1"]').text(),
        ).toContain('Hook v1.2');
        expect(fieldValue(wrapper, 'input[name="targets[0][key]"]')).toBe(
            'Deal Sync',
        );
        expect(fieldValue(wrapper, 'input[name="targets[1][key]"]')).toBe(
            'Hook v1.2',
        );
    });

    it('keeps saving disabled until a target changes', async () => {
        const wrapper = mountPlaceholders();

        expect(wrapper.get('[data-form-save]').attributes('disabled')).toBe('');

        await wrapper
            .get('#placeholder-target-0')
            .setValue('https://hooks.example.test/neu/deal-sync');

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
        expect(wrapper.get('[data-form-save]').text()).toBe('Speichern');
    });

    it('shows a server message beneath the field it belongs to', () => {
        const message = 'Hook v1.2 muss eine gültige Adresse sein.';

        formErrors.current = { 'targets.1.target_url': message };

        const messages = mountPlaceholders()
            .findAllComponents(InputError)
            .map((error) => error.props('message'));

        expect(messages).toHaveLength(2);
        expect(messages.indexOf(message)).toBe(1);
    });

    it('wires the shared form actions and asks before leaving with pending changes', async () => {
        const wrapper = mountPlaceholders();

        expect(wrapper.findComponent(FormActions).exists()).toBe(true);
        expect(wrapper.findComponent(UnsavedChangesDialog).exists()).toBe(true);

        await wrapper
            .get('#placeholder-target-1')
            .setValue('https://hooks.example.test/neu/hook-v12');
        await wrapper.get('[data-form-cancel]').trigger('click');
        await nextTick();

        expect(visitMock).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain('Änderungen verwerfen?');
    });

    it('returns to the configuration page through Wayfinder when cancelling without changes', async () => {
        const wrapper = mountPlaceholders();

        await wrapper.get('[data-form-cancel]').trigger('click');

        expect(visitMock).toHaveBeenCalledTimes(1);
        expect(visitMock.mock.calls[0][0]).toBe(
            ConfigBundleController.index.url(),
        );
    });

    it('explains why the webhook targets have to be entered here', () => {
        const explanation = mountPlaceholders().get(
            '[data-placeholder-explanation]',
        );

        expect(explanation.text().length).toBeGreaterThan(0);
        expect(explanation.text()).toContain('Webhook');
    });

    it('disables every target field and shows the server reason when saving is refused', () => {
        const reason =
            'Die Übernahme ist bereits zur Genehmigung eingereicht; die Webhook-Ziele lassen sich nicht mehr ändern.';
        const wrapper = mountPlaceholders({
            can_save: false,
            save_reason: reason,
        });

        expect(wrapper.text()).toContain(reason);
        expect(
            wrapper.get('#placeholder-target-0').attributes('disabled'),
        ).toBeDefined();
        expect(
            wrapper.get('#placeholder-target-1').attributes('disabled'),
        ).toBeDefined();
    });
});
