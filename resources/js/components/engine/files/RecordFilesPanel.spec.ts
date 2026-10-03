import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import RecordFilesPanel from '@/components/engine/files/RecordFilesPanel.vue';

const mocks = vi.hoisted(() => ({ useRecordFiles: vi.fn() }));
vi.mock('@/composables/useRecordFiles', () => ({
    useRecordFiles: mocks.useRecordFiles,
}));
let state: ReturnType<typeof makeState>;
function makeState() {
    return {
        error: ref<string | null>(null),
        loading: ref(false),
        uploading: ref(false),
        progress: ref<number | undefined>(),
        canUpload: ref(true),
        accept: ref('application/pdf'),
        maxSizeKb: ref(10240),
        load: vi.fn(),
        upload: vi.fn().mockResolvedValue(true),
    };
}
function panel(readonly = false) {
    return mount(RecordFilesPanel, {
        props: { recordId: 'record-1', readonly },
    });
}
beforeEach(() => {
    state = makeState();
    mocks.useRecordFiles.mockReturnValue(state);
});

describe('RecordFilesPanel', () => {
    it('offers only upload and points to the timeline instead of listing attachments', () => {
        const wrapper = panel();
        expect(wrapper.text()).toContain('Zeitstrang');
        expect(wrapper.find('input[type="file"]').exists()).toBe(true);
        expect(wrapper.find('ul').exists()).toBe(false);
        expect(wrapper.find('[data-record-file]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Noch keine Dateien');
    });
    it('does not offer upload on a deleted record', () => {
        expect(panel(true).find('input[type="file"]').exists()).toBe(false);
    });
    it('respects upload permissions', () => {
        state.canUpload.value = false;
        const wrapper = panel();
        expect(wrapper.find('input[type="file"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('keine Berechtigung');
    });
    it('refreshes the timeline only after a successful upload', async () => {
        const wrapper = panel();
        const input = wrapper.get('input[type="file"]');
        const selected = new File(['contents'], 'test.pdf', {
            type: 'application/pdf',
        });
        Object.defineProperty(input.element, 'files', { value: [selected] });
        await input.trigger('change');
        await flushPromises();
        expect(state.upload).toHaveBeenCalledWith(selected);
        expect(wrapper.emitted('changed')).toHaveLength(1);
        state.upload.mockResolvedValue(false);
        await input.trigger('change');
        await flushPromises();
        expect(wrapper.emitted('changed')).toHaveLength(1);
    });
    it('shows progress and disables the dropzone during upload', () => {
        state.uploading.value = true;
        state.progress.value = 42;
        const wrapper = panel();
        expect(wrapper.get('progress').attributes('value')).toBe('42');
        expect(
            wrapper.get('input[type="file"]').attributes('disabled'),
        ).toBeDefined();
    });
    it('offers retry after a loading error', async () => {
        state.error.value = 'Verbindung fehlgeschlagen';
        const wrapper = panel();
        expect(wrapper.get('[role="alert"]').text()).toContain(
            'Verbindung fehlgeschlagen',
        );
        await wrapper.get('[role="alert"] button').trigger('click');
        expect(state.load).toHaveBeenCalled();
    });
});
