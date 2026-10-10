import { describe, expect, it } from 'vitest';
import {
    sourceIcon,
    sourceLabel,
    stageFacetLabel,
    TIMELINE_TABS,
} from '@/components/engine/timeline/sourceLabels';

const sourceKeys = [
    'activity',
    'note',
    'reminder',
    'file',
    'automation_run',
    'field_change',
    'stage_change',
    'relation',
    'merge',
];

describe('sourceLabels — the tab strip of the record timeline', () => {
    it('mirrors the tabs above the timeline in the agreed order', () => {
        expect(TIMELINE_TABS.map((tab) => tab.label)).toEqual([
            'Alle',
            'Notizen',
            'Aktivität',
            'Erinnerungen',
            'Dateien',
            'Änderungen',
        ]);
    });

    it('carries no stage tab — a stage change is a change', () => {
        const changes = TIMELINE_TABS.find((tab) => tab.value === 'change');

        expect(TIMELINE_TABS.map((tab) => tab.value)).not.toContain(
            'stage_change',
        );
        expect(changes?.sources).toEqual([
            'field_change',
            'stage_change',
            'relation',
            'merge',
        ]);
    });

    it('reaches every source key through exactly one tab', () => {
        for (const key of sourceKeys.filter(
            (key) => !['automation_run', 'document'].includes(key),
        )) {
            expect(
                TIMELINE_TABS.filter((tab) => tab.sources.includes(key)),
            ).toHaveLength(1);
        }
    });

    it('loads all sources on all and activities on activity', () => {
        expect(
            TIMELINE_TABS.find((tab) => tab.value === 'all')?.sources,
        ).toEqual([]);
        expect(
            TIMELINE_TABS.find((tab) => tab.value === 'activity')?.sources,
        ).toEqual(['activity']);
    });
});

describe('sourceLabels — the marker of a timeline entry', () => {
    it('gives every source that has one its own icon', () => {
        for (const key of ['note', 'reminder', 'file', 'automation_run']) {
            expect(sourceIcon(key)).not.toBeNull();
        }
    });

    it('leaves a change without an icon so it stays a plain dot', () => {
        expect(sourceIcon('field_change')).toBeNull();
        expect(sourceIcon('stage_change')).toBeNull();
    });
});

describe('sourceLabels — German copy for every source', () => {
    it('gives every known source key its own label instead of echoing the key', () => {
        const labels = sourceKeys.map((key) => sourceLabel(key));

        expect(new Set(labels).size).toBe(sourceKeys.length);

        for (const [index, label] of labels.entries()) {
            expect(label.trim()).not.toBe('');
            expect(label).not.toBe(sourceKeys[index]);
            expect(label).not.toContain('_');
        }
    });

    it('falls back to one neutral label for a source key it does not know', () => {
        expect(() => sourceLabel('webinar')).not.toThrow();
        expect(sourceLabel('webinar')).toBe(sourceLabel('podcast_episode'));
        expect(sourceLabel('webinar')).not.toBe('webinar');
        expect(sourceLabel('webinar').trim()).not.toBe('');
    });
});

describe('sourceLabels — stage facets carry tenant-defined labels', () => {
    it('prefers the German label of a stage facet', () => {
        expect(
            stageFacetLabel({
                id: '01STATUS0000000000000000AA',
                key: 'won',
                labels: { de: 'Gewonnen', en: 'Won' },
            }),
        ).toBe('Gewonnen');
    });

    it('takes the first available label when German is missing', () => {
        expect(
            stageFacetLabel({
                id: '01STATUS0000000000000000AA',
                key: 'won',
                labels: { en: 'Won' },
            }),
        ).toBe('Won');
    });

    it('falls back to the stage key when no label survived', () => {
        expect(
            stageFacetLabel({
                id: '01STATUS0000000000000000AA',
                key: 'won',
                labels: {},
            }),
        ).toBe('won');
    });

    it('never renders the bare identifier of a deleted stage', () => {
        const label = stageFacetLabel({ id: '01STATUS0000000000000000AA' });

        expect(label.trim()).not.toBe('');
        expect(label).not.toContain('01STATUS0000000000000000AA');
    });

    it('renders a missing facet as readable copy instead of null', () => {
        const label = stageFacetLabel(null);

        expect(label.trim()).not.toBe('');
        expect(label).not.toBe('null');
        expect(label).not.toBe('undefined');
    });
});
