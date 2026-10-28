import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { toast } from 'vue-sonner';
import { Toaster } from '@/components/ui/sonner';
import { appCss } from '@/tests/appCss';

const INTENT_ACCENTS: Record<string, string> = {
    success: 'lime',
    error: 'red',
    warning: 'orange',
    info: 'blue',
};

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

    it('leaves the neutral toast surface to the design tokens', () => {
        mountToaster();

        const style = toasterElement().style;

        expect(style.getPropertyValue('--normal-bg')).toBe(
            'var(--ds-surface-overlay)',
        );
        expect(style.getPropertyValue('--normal-text')).toBe('var(--ds-text)');
        expect(style.getPropertyValue('--normal-border')).toBe(
            'var(--ds-border)',
        );
    });

    it('paints every intent on the Atlassian accent of its hue', () => {
        mountToaster();

        const style = toasterElement().style;

        for (const [type, accent] of Object.entries(INTENT_ACCENTS)) {
            expect(style.getPropertyValue(`--${type}-bg`)).toBe(
                `var(--ds-bg-accent-${accent}-subtlest)`,
            );
            expect(style.getPropertyValue(`--${type}-border`)).toBe(
                `var(--ds-border-accent-${accent})`,
            );
            expect(style.getPropertyValue(`--${type}-text`)).toBe(
                `var(--ds-text-accent-${accent}-bolder)`,
            );
        }
    });

    it('tints the icon of every intent with its own accent', () => {
        for (const accent of Object.values(INTENT_ACCENTS)) {
            expect(appCss()).toContain(`var(--ds-icon-accent-${accent})`);
        }
    });

    it('gives a toast room to breathe', async () => {
        mountToaster();
        toast.success('Datensatz gespeichert');
        await flush();

        expect(toasterElement().style.getPropertyValue('--width')).toBe(
            '460px',
        );
        expect(appCss()).toContain('min-block-size: 64px');
        expect(appCss()).toContain('padding: var(--ds-space-200)');
    });

    it('leaves the dismiss button flat and free of hover paint', () => {
        expect(appCss()).not.toMatch(/\[data-close-button\][^{]*:hover/);

        const wrapper = mountToaster();
        const classes = (
            wrapper.props('toastOptions') as
                | { classes?: Record<string, string> }
                | undefined
        )?.classes;

        expect(classes?.closeButton ?? '').not.toContain('hover:');
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

    it('out-specifies the stylesheet vue-sonner injects after ours', () => {
        const selectors = appCss().match(/^\[data-sonner-toast\]/gm);

        expect(selectors).toBeNull();
    });
});
