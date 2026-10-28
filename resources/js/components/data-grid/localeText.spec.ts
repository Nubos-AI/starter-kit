import { describe, expect, it } from 'vitest';
import { agGridLocaleDe } from '@/components/data-grid/localeText';

const FILTER_KEYS = [
    'contains',
    'notContains',
    'equals',
    'notEqual',
    'startsWith',
    'endsWith',
    'blank',
    'notBlank',
    'lessThan',
    'greaterThan',
    'lessThanOrEqual',
    'greaterThanOrEqual',
    'inRange',
    'inRangeStart',
    'inRangeEnd',
    'before',
    'after',
    'andCondition',
    'orCondition',
    'filterOoo',
    'applyFilter',
    'clearFilter',
    'resetFilter',
    'cancelFilter',
    'textFilter',
    'numberFilter',
    'dateFilter',
    'setFilter',
    'empty',
] as const;

const ENGLISH_DEFAULTS = [
    'Contains',
    'Does not contain',
    'Equals',
    'Does not equal',
    'Begins with',
    'Ends with',
    'Blank',
    'Not blank',
    'AND',
    'OR',
    'Filter...',
    'Apply',
    'Clear',
    'Reset',
    'Cancel',
    'Text Filter',
    'Number Filter',
    'Date Filter',
    'Set Filter',
    'Choose one',
    'No Rows To Show',
    'Loading...',
];

describe('agGridLocaleDe — the column filter speaks German', () => {
    it.each(FILTER_KEYS)('carries a German text for %s', (key) => {
        expect(agGridLocaleDe[key]).toBeTypeOf('string');
        expect(agGridLocaleDe[key]).not.toBe('');
    });

    it('names the text operators the way the rest of the app does', () => {
        expect(agGridLocaleDe.contains).toBe('Enthält');
        expect(agGridLocaleDe.notContains).toBe('Enthält nicht');
        expect(agGridLocaleDe.equals).toBe('Ist gleich');
        expect(agGridLocaleDe.notEqual).toBe('Ist ungleich');
        expect(agGridLocaleDe.startsWith).toBe('Beginnt mit');
        expect(agGridLocaleDe.endsWith).toBe('Endet mit');
        expect(agGridLocaleDe.blank).toBe('Leer');
        expect(agGridLocaleDe.notBlank).toBe('Nicht leer');
    });

    it('joins two conditions in German', () => {
        expect(agGridLocaleDe.andCondition).toBe('UND');
        expect(agGridLocaleDe.orCondition).toBe('ODER');
    });

    it('covers the overlays the grid shows without rows', () => {
        expect(agGridLocaleDe.noRowsToShow).toBe('Keine Einträge vorhanden');
        expect(agGridLocaleDe.noMatchingRows).toBe('Keine Treffer');
        expect(agGridLocaleDe.loadingOoo).toBe('Wird geladen…');
    });

    it('leaves no AG Grid default text in the map', () => {
        const values = Object.values(agGridLocaleDe);

        for (const english of ENGLISH_DEFAULTS) {
            expect(values).not.toContain(english);
        }
    });

    it('names the months in German', () => {
        expect(agGridLocaleDe.january).toBe('Januar');
        expect(agGridLocaleDe.december).toBe('Dezember');
    });
});
