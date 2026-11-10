import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Ref } from 'vue';
import { nextTick, ref } from 'vue';
import ImportWizard from '@/pages/import/ImportWizard.vue';
import { setUrlDefaults } from '@/wayfinder';

type BeforeHandler = (event: {
    detail: { visit: Record<string, unknown> };
}) => boolean | void;

const { guards, wizardState } = vi.hoisted(() => ({
    guards: [] as BeforeHandler[],
    wizardState: { uploadResult: null as Ref<unknown> | null },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: {
        on: vi.fn((name: string, handler: BeforeHandler) => {
            if (name === 'before') {
                guards.push(handler);
            }

            return vi.fn();
        }),
        visit: vi.fn(),
    },
    usePage: () => ({
        url: '/nubos/engine/records/companies/import',
        props: { auth: { user: null, can: {}, authority: null } },
    }),
}));

vi.mock('@/composables/useImportWizard', () => ({
    useImportWizard: () => {
        const uploadResult = ref<unknown>(null);

        wizardState.uploadResult = uploadResult;

        return {
            currentStep: ref('upload'),
            uploadResult,
            formatOverride: ref({}),
            sheet: ref(null),
            mapping: ref({ columns: {}, formats: {} }),
            duplicateMode: ref('skip'),
            missingOptionMode: ref('error'),
            dryRunResult: ref(null),
            loading: ref(false),
            error: ref(null),
            presets: ref([]),
            canStartImport: ref(false),
            upload: vi.fn(),
            setFormatOverride: vi.fn(),
            selectSheet: vi.fn(),
            autoMatch: vi.fn(),
            setMapping: vi.fn(),
            savePreset: vi.fn(),
            loadPreset: vi.fn(),
            runDryRun: vi.fn(),
        };
    },
}));

const stub = { template: '<div />' };

const mounted: ReturnType<typeof mount>[] = [];

function mountWizard(): ReturnType<typeof mount> {
    const wrapper = mount(ImportWizard, {
        props: { objectType: 'companies', fields: [] },
        global: {
            stubs: {
                UploadStep: stub,
                SheetPicker: stub,
                MappingStep: stub,
                ColumnFormatOverride: stub,
                DryRunSummary: stub,
                ImportProgress: stub,
                UnsavedChangesDialog: {
                    props: ['open'],
                    template:
                        '<div data-unsaved-dialog :data-open="open ? \'\' : undefined" />',
                },
            },
        },
    });

    mounted.push(wrapper);

    return wrapper;
}

function navigate(): boolean | void {
    const guard = guards.at(-1);

    if (guard === undefined) {
        throw new Error('the wizard installed no navigation guard');
    }

    return guard({
        detail: {
            visit: {
                method: 'get',
                url: '/nubos/engine/records/companies',
                only: [],
                except: [],
            },
        },
    });
}

beforeEach(() => {
    setUrlDefaults({ activeTeam: 'nubos' });
    guards.splice(0);
});

afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('import/ImportWizard — unsaved changes guard', () => {
    it('lets the user leave an untouched wizard', () => {
        const wrapper = mountWizard();

        expect(navigate()).toBeUndefined();
        expect(
            wrapper.get('[data-unsaved-dialog]').attributes('data-open'),
        ).toBeUndefined();
    });

    it('asks before leaving once a file was uploaded', async () => {
        const wrapper = mountWizard();
        const uploadResult = wizardState.uploadResult;

        if (uploadResult === null) {
            throw new Error('the wizard state was not created');
        }

        uploadResult.value = {
            path: 'imports/companies.csv',
            format: 'csv',
            sheets: [],
            headerSuggestion: [],
        };
        await nextTick();

        expect(navigate()).toBe(false);
        await nextTick();
        expect(
            wrapper.get('[data-unsaved-dialog]').attributes('data-open'),
        ).toBe('');
    });
});
