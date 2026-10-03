import { describe, expect, it } from 'vitest';
import { appCss } from '@/tests/appCss';

const FIELD_SOURCES = [
    '../components/ui/input/Input.vue',
    '../components/ui/textarea/Textarea.vue',
    '../components/ui/select/SelectTrigger.vue',
    '../components/ui/combobox/Combobox.vue',
    '../components/ui/multi-select/MultiSelect.vue',
    '../components/ui/checkbox/Checkbox.vue',
];

const THEME_SELECTORS = [':root', '.dark'];

const sources = import.meta.glob('../components/ui/**/*.vue', {
    eager: true,
    query: '?raw',
    import: 'default',
}) as Record<string, string>;

function sourceOf(path: string): string {
    const found = sources[path];

    if (found === undefined) {
        throw new Error(`field source ${path} is missing`);
    }

    return found;
}

/**
 * @param selector a selector written at the start of a line in app.css
 */
function blockOf(css: string, selector: string): string {
    const opening = new RegExp(`^${selector.replace('.', '\\.')} \\{$`, 'm');
    const start = css.search(opening);

    if (start === -1) {
        throw new Error(`app.css declares no ${selector} block`);
    }

    const end = css.indexOf('\n}', start);

    return css.slice(start, end);
}

function neutralStep(block: string, token: string): number {
    const declaration = new RegExp(
        `--ds-${token}:\\s*var\\(--ds-palette-(?:dark-)?neutral-(\\d+)\\)`,
    ).exec(block);

    if (declaration === null) {
        throw new Error(`--ds-${token} is not pinned to a neutral step`);
    }

    return Number(declaration[1]);
}

describe('a field marks its focus with one grey pixel, never a blue ring', () => {
    it.each(FIELD_SOURCES)('leaves %s without a focus ring', (path) => {
        const source = sourceOf(path);

        expect(source).not.toContain('focus-visible:ring-2');
        expect(source).not.toContain('ring-ring');
    });

    it.each(FIELD_SOURCES)('darkens the border of %s on focus', (path) => {
        expect(sourceOf(path)).toContain('focus-visible:border-bold');
    });

    it.each(FIELD_SOURCES)('keeps the resting border of %s subtle', (path) => {
        expect(sourceOf(path)).toContain('border-input');
    });
});

describe('the resting grey and the focus grey are told apart', () => {
    it.each(THEME_SELECTORS)(
        'rests %s on a lighter grey than it focuses on',
        (selector) => {
            const block = blockOf(appCss(), selector);

            expect(neutralStep(block, 'border-input')).toBeLessThan(
                neutralStep(block, 'border-bold'),
            );
        },
    );

    it.each(THEME_SELECTORS)(
        'keeps the two greys of %s at least three palette steps apart',
        (selector) => {
            const block = blockOf(appCss(), selector);
            const resting = neutralStep(block, 'border-input');
            const focused = neutralStep(block, 'border-bold');

            expect(focused - resting).toBeGreaterThanOrEqual(300);
        },
    );
});
