import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import ConfigBundleController from '@/actions/App/Http/Controllers/ConfigBundle/ConfigBundleController';
import InputError from '@/components/InputError.vue';
import Card from '@/components/ui/card/Card.vue';
import FileDropzone from '@/components/ui/file-dropzone/FileDropzone.vue';
import Index from '@/pages/config/Index.vue';

interface UploadFormState {
    file: File | null;
    processing: boolean;
    errors: Record<string, string>;
    post: (...args: unknown[]) => unknown;
}

interface IndexProps {
    can_export: boolean;
    export_reason: string | null;
    can_import: boolean;
    import_reason: string | null;
}

const { postMock, forms, pageProps } = vi.hoisted(() => ({
    postMock: vi.fn(),
    forms: [] as UploadFormState[],
    pageProps: { errors: {} as Record<string, string> },
}));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    return {
        Head: { template: '<head-stub><slot /></head-stub>' },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
        router: { on: vi.fn(() => vi.fn()), visit: vi.fn() },
        usePage: () => ({
            url: '/nubos/engine/config',
            props: pageProps,
        }),
        useForm: (initial: { file: File | null }) => {
            const form = reactive<UploadFormState>({
                file: initial.file,
                processing: false,
                errors: {},
                post: postMock,
            });

            forms.push(form);

            return form;
        },
    };
});

type Wrapper = ReturnType<typeof mount>;

const passthrough = { template: '<div><slot /></div>' };

const stubs = {
    TooltipProvider: passthrough,
    Tooltip: passthrough,
    TooltipTrigger: passthrough,
    TooltipContent: passthrough,
};

const mounted: Wrapper[] = [];

function mountIndex(overrides: Partial<IndexProps> = {}): Wrapper {
    const wrapper = mount(Index, {
        props: {
            can_export: true,
            export_reason: null,
            can_import: true,
            import_reason: null,
            ...overrides,
        },
        global: { stubs },
    });

    mounted.push(wrapper);

    return wrapper;
}

function currentForm(): UploadFormState {
    const form = forms[forms.length - 1];

    if (form === undefined) {
        throw new Error('The page created no upload form');
    }

    return form;
}

async function chooseArchive(wrapper: Wrapper): Promise<File> {
    const archive = new File(['PK'], 'konfiguration.zip', {
        type: 'application/zip',
    });

    wrapper.findComponent(FileDropzone).vm.$emit('select', archive);
    await nextTick();

    return archive;
}

beforeEach(() => {
    postMock.mockReset();
    forms.length = 0;
    pageProps.errors = {};
});

afterEach(() => {
    while (mounted.length > 0) {
        mounted.pop()?.unmount();
    }
});

describe('config/Index', () => {
    it('links the download to the Wayfinder export route when exporting is allowed', () => {
        const wrapper = mountIndex();
        const download = wrapper.get('[data-config-export]');

        expect(download.attributes('href')).toBe(
            ConfigBundleController.exportMethod.url(),
        );
        expect(download.attributes('disabled')).toBeUndefined();
        expect(wrapper.find('[data-config-export-reason]').exists()).toBe(
            false,
        );
    });

    it('keeps the download visible but disabled with the server reason when exporting is refused', () => {
        const reason = 'Ihnen fehlt die Berechtigung „config.export“.';
        const wrapper = mountIndex({
            can_export: false,
            export_reason: reason,
        });
        const download = wrapper.get('[data-config-export]');

        expect(download.attributes('disabled')).toBeDefined();
        expect(download.attributes('href')).toBeUndefined();
        expect(wrapper.get('[data-config-export-reason]').text()).toContain(
            reason,
        );
    });

    it('keeps the upload disabled until an archive is chosen', async () => {
        const wrapper = mountIndex();

        expect(
            wrapper.get('[data-config-import-submit]').attributes('disabled'),
        ).toBeDefined();

        const archive = await chooseArchive(wrapper);

        expect(currentForm().file).toBe(archive);
        expect(
            wrapper.get('[data-config-import-submit]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('disables the upload while the form is processing', async () => {
        const wrapper = mountIndex();

        await chooseArchive(wrapper);

        currentForm().processing = true;
        await nextTick();

        expect(
            wrapper.get('[data-config-import-submit]').attributes('disabled'),
        ).toBeDefined();
    });

    it('disables choosing and uploading and shows the server reason when importing is refused', async () => {
        const reason =
            'Der Import schreibt in diesen Mandanten und verlangt eskalierte Autorität.';
        const wrapper = mountIndex({
            can_import: false,
            import_reason: reason,
        });

        expect(wrapper.findComponent(FileDropzone).props('disabled')).toBe(
            true,
        );
        expect(
            wrapper.get('[data-config-import-submit]').attributes('disabled'),
        ).toBeDefined();
        expect(wrapper.get('[data-config-import-reason]').text()).toContain(
            reason,
        );
    });

    it('sends the chosen archive to the Wayfinder upload route', async () => {
        const wrapper = mountIndex();

        await chooseArchive(wrapper);
        await wrapper.get('[data-config-import-submit]').trigger('click');
        await nextTick();

        expect(postMock).toHaveBeenCalledTimes(1);
        expect(postMock.mock.calls[0][0]).toBe(
            ConfigBundleController.upload.url(),
        );
    });

    it('shows the server message for the export field inside the export card', () => {
        const message =
            'Zwei Artefakte der Art „skills“ ergäben denselben Dateinamen: „Revenue Q1“ und „Revenue & Q1“.';

        pageProps.errors = { export: message };

        const wrapper = mountIndex();
        const exportCards = wrapper
            .findAllComponents(Card)
            .filter((card) => card.find('[data-config-export]').exists());

        expect(exportCards).toHaveLength(1);

        const exportMessages = exportCards[0]
            .findAllComponents(InputError)
            .map((error) => error.props('message'));

        expect(exportMessages).toContain(message);
        expect(exportCards[0].text()).toContain(message);
    });

    it('shows the server message for the file field beneath the file choice', async () => {
        const message =
            'Dieses Bundle wurde mit einer neueren Version erzeugt (3) als diese Installation unterstützt (2). Bitte aktualisieren Sie die Anwendung.';
        const wrapper = mountIndex();

        currentForm().errors = { file: message };
        await nextTick();

        const messages = wrapper
            .findAllComponents(InputError)
            .map((error) => error.props('message'));

        expect(messages).toContain(message);
    });
});
