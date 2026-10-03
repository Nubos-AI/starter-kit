import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import FileDropzone from '@/components/ui/file-dropzone/FileDropzone.vue';

function dropEvent(files: File[]): DragEvent {
    const event = new Event('drop', {
        bubbles: true,
        cancelable: true,
    }) as DragEvent;

    Object.defineProperty(event, 'dataTransfer', {
        value: { files, types: ['Files'] },
    });

    return event;
}

function jsonFile(name = 'template.json'): File {
    return new File(['{}'], name, { type: 'application/json' });
}

describe('FileDropzone', () => {
    it('emits the dropped file', async () => {
        const wrapper = mount(FileDropzone, {
            props: { inputId: 'drop-1', accept: 'application/json' },
        });
        const file = jsonFile();

        wrapper.get('[data-slot="file-dropzone"]').element.dispatchEvent(
            dropEvent([file]),
        );

        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('select')).toHaveLength(1);
        expect(wrapper.emitted('select')![0][0]).toBe(file);
    });

    it('marks itself as active while a file hovers over it', async () => {
        const wrapper = mount(FileDropzone, {
            props: { inputId: 'drop-2', accept: 'application/json' },
        });
        const zone = wrapper.get('[data-slot="file-dropzone"]');

        await zone.trigger('dragover');

        expect(zone.attributes('data-dragging')).toBe('true');

        await zone.trigger('dragleave');

        expect(zone.attributes('data-dragging')).toBe('false');
    });

    it('emits the file picked through the hidden input', async () => {
        const wrapper = mount(FileDropzone, {
            props: { inputId: 'drop-3', accept: 'application/json' },
        });
        const input = wrapper.get('input[type="file"]');
        const file = jsonFile();

        Object.defineProperty(input.element, 'files', { value: [file] });

        await input.trigger('change');

        expect(wrapper.emitted('select')![0][0]).toBe(file);
    });

    it('ignores a drop that carries no file', async () => {
        const wrapper = mount(FileDropzone, {
            props: { inputId: 'drop-4', accept: 'application/json' },
        });

        wrapper
            .get('[data-slot="file-dropzone"]')
            .element.dispatchEvent(dropEvent([]));

        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('select')).toBeUndefined();
    });

    it('shows the name of the accepted file', async () => {
        const wrapper = mount(FileDropzone, {
            props: {
                inputId: 'drop-5',
                accept: 'application/json',
                fileName: 'export.json',
            },
        });

        expect(wrapper.text()).toContain('export.json');
    });

    it('stays inert while disabled', async () => {
        const wrapper = mount(FileDropzone, {
            props: {
                inputId: 'drop-6',
                accept: 'application/json',
                disabled: true,
            },
        });

        wrapper
            .get('[data-slot="file-dropzone"]')
            .element.dispatchEvent(dropEvent([jsonFile()]));

        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('select')).toBeUndefined();
    });
});
