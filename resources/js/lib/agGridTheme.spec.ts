import { describe, expect, it } from 'vitest';
import {
    agGridDensityParams,
    agGridThemeParams,
    buildAgGridTheme,
} from '@/lib/agGridTheme';

describe('agGridTheme (SC-12)', () => {
    it('couples core colors to shadcn/Tailwind CSS custom properties', () => {
        expect(agGridThemeParams.backgroundColor).toBe(
            'var(--table-background)',
        );
        expect(agGridThemeParams.foregroundColor).toBe(
            'var(--table-foreground)',
        );
        expect(agGridThemeParams.accentColor).toBe('var(--table-accent)');
        expect(agGridThemeParams.borderColor).toBe('var(--table-border)');
    });

    it('couples the radius to the design-token variable', () => {
        expect(agGridThemeParams.borderRadius).toBe('var(--table-radius)');
    });

    it('drives dark-mode purely through CSS variables (no hardcoded hex colors)', () => {
        const colorValues = [
            agGridThemeParams.backgroundColor,
            agGridThemeParams.foregroundColor,
            agGridThemeParams.accentColor,
            agGridThemeParams.borderColor,
            agGridThemeParams.borderRadius,
        ];

        for (const value of colorValues) {
            expect(value).toMatch(/^var\(--[a-z-]+\)$/);
        }
    });

    it('inherits typography and applies compact, token-aligned density', () => {
        expect(agGridThemeParams.fontFamily).toBe('inherit');
        expect(agGridThemeParams.fontSize).toBe(12);
        expect(agGridThemeParams.spacing).toBe(4);
        expect(agGridThemeParams.headerFontSize).toBe(12);
        expect(agGridThemeParams.rowHeight).toBe(32);
        expect(agGridThemeParams.headerHeight).toBe(34);
    });

    it('produces a usable ag-grid Theme object per density', () => {
        expect(buildAgGridTheme('compact')).toBeDefined();
        expect(buildAgGridTheme('comfortable')).toBeDefined();
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
