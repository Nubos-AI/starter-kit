import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { toast } from 'vue-sonner';
import { Toaster } from '@/shared/ui/primitives/sonner';

function mountToaster() {
    return mount(Toaster, { attachTo: document.body });
}

function toasterElement(): HTMLElement {
    const element = document.querySelector('[data-sonner-toaster]');

    if (element === null) {
        throw new Error('the toaster did not render');
    }

    return element as HTMLElement;
}

async function flush(): Promise<void> {
    await new Promise((resolve) => setTimeout(resolve, 0));
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('Toaster', () => {
    it('sits in the bottom left corner', () => {
        mountToaster();

        expect(toasterElement().getAttribute('data-y-position')).toBe('bottom');
        expect(toasterElement().getAttribute('data-x-position')).toBe('left');
    });

    it('renders the dismiss button in the top right corner of the toast', async () => {
        mountToaster();
        toast.success('Datensatz gespeichert');
        await flush();

        const button = document.querySelector('[data-close-button]');

        expect(button).not.toBeNull();
        expect(button?.getAttribute('aria-label')).toBe('Schließen');
        expect(button?.getAttribute('data-close-button-position')).toBe(
            'top-right',
        );
    });

    it('marks the toasts as rich so the intent surfaces apply', async () => {
        const wrapper = mountToaster();

        toast.success('Datensatz gespeichert');
        await flush();

        expect(wrapper.props('richColors')).toBe(true);
        expect(
            document
                .querySelector('[data-sonner-toast]')
                ?.getAttribute('data-rich-colors'),
        ).toBe('true');
    });
});
