import { defineComponent } from 'vue';

const passthrough = { template: '<div><slot /></div>' };
const slotOnly = { template: '<slot />' };

export const comboboxPrimitiveStubs = {
    ComboboxRoot: {
        props: ['modelValue', 'open', 'disabled', 'multiple', 'ignoreFilter'],
        emits: ['update:open'],
        template: '<div><slot /></div>',
    },
    ComboboxAnchor: slotOnly,
    ComboboxTrigger: slotOnly,
    ComboboxPortal: passthrough,
    ComboboxContent: passthrough,
    ComboboxViewport: passthrough,
    ComboboxInput: {
        props: ['modelValue', 'placeholder'],
        emits: ['update:modelValue'],
        template:
            '<input :value="modelValue" :placeholder="placeholder" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    },
    ComboboxItem: defineComponent({
        props: ['value', 'disabled', 'title'],
        emits: ['select'],
        setup(props): void {
            if (props.value === '') {
                throw new Error(
                    'A <ComboboxItem /> must have a value prop that is not an empty string.',
                );
            }
        },
        template:
            '<button type="button" :disabled="disabled" :title="title" @click="$emit(\'select\', $event)"><slot /></button>',
    }),
};

export function makeScrollable(
    element: Element,
    metrics: { scrollTop: number; clientHeight: number; scrollHeight: number },
): void {
    let scrollTop = metrics.scrollTop;

    Object.defineProperties(element, {
        scrollTop: {
            configurable: true,
            get: () => scrollTop,
            set: (value: number) => {
                scrollTop = value;
            },
        },
        clientHeight: { value: metrics.clientHeight, configurable: true },
        scrollHeight: { value: metrics.scrollHeight, configurable: true },
    });
}
