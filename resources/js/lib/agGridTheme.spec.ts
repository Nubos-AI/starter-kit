import { describe, expect, it } from 'vitest';
import { agGridDensityParams, agGridThemeParams } from '@/lib/agGridTheme';

describe('agGridTheme (SC-12)', () => {
    it('couples colors and radius to the table CSS variables so dark mode needs no hardcoded colour', () => {
        const colorValues = [
            agGridThemeParams.backgroundColor,
            agGridThemeParams.foregroundColor,
            agGridThemeParams.accentColor,
            agGridThemeParams.borderColor,
            agGridThemeParams.borderRadius,
        ];

        expect(colorValues).toEqual([
            'var(--table-background)',
            'var(--table-foreground)',
            'var(--table-accent)',
            'var(--table-border)',
            'var(--table-radius)',
        ]);

        for (const value of colorValues) {
            expect(value).toMatch(/^var\(--table-[a-z-]+\)$/);
        }
    });

    it('inherits typography and applies compact, token-aligned density', () => {
        expect(agGridThemeParams.fontFamily).toBe('inherit');
        expect(agGridThemeParams.fontSize).toBe(14);
        expect(agGridThemeParams.spacing).toBe(4);
        expect(agGridThemeParams.headerFontSize).toBe(13);
        expect(agGridThemeParams.rowHeight).toBe(44);
        expect(agGridThemeParams.headerHeight).toBe(42);
    });

    it('keeps the comfortable density roomier than the compact one', () => {
        expect(agGridDensityParams.comfortable.rowHeight).toBeGreaterThan(
            agGridDensityParams.compact.rowHeight,
        );
        expect(agGridDensityParams.comfortable.spacing).toBeGreaterThan(
            agGridDensityParams.compact.spacing,
        );
    });

    it('matches the compact density to the documented base parameters', () => {
        expect(agGridDensityParams.compact.rowHeight).toBe(
            agGridThemeParams.rowHeight,
        );
        expect(agGridDensityParams.compact.spacing).toBe(
            agGridThemeParams.spacing,
        );
    });
});
