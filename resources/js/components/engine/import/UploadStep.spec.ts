import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import UploadStep from '@/components/engine/import/UploadStep.vue';
import FileDropzone from '@/components/ui/file-dropzone/FileDropzone.vue';

function mountUploadStep(loading = false) {
    return mount(UploadStep, {
        props: {
            loading,
            error: null,
            uploadResult: null,
            formatOverride: {},
        },
    });
}

describe('import/UploadStep', () => {
    it('uploads a file handed over by the dropzone', async () => {
        const wrapper = mountUploadStep();
        const file = new File(['a,b'], 'kontakte.csv', { type: 'text/csv' });

        wrapper.findComponent(FileDropzone).vm.$emit('select', file);

        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('upload')).toHaveLength(1);
        expect(wrapper.emitted('upload')![0][0]).toBe(file);
        expect(wrapper.findComponent(FileDropzone).props('fileName')).toBe(
            'kontakte.csv',
        );
    });

    it('blocks the dropzone while an upload is running', () => {
        const wrapper = mountUploadStep(true);

        expect(wrapper.findComponent(FileDropzone).props('disabled')).toBe(
            true,
        );
    });
});
