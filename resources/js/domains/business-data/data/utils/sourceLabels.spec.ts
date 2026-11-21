import { describe, expect, it } from 'vitest';
import { t } from '@/shared/testing/i18n';
import { sourceIcon, sourceLabel, TIMELINE_TABS } from './sourceLabels';

const sourceKeys = ['activity', 'note', 'reminder', 'file', 'field_change', 'relation', 'merge'];

describe('sourceLabels — the tab strip of the record timeline', () => {
  it('mirrors the tabs above the timeline in the agreed order', () => {
    expect(TIMELINE_TABS.map((tab) => tab.value)).toEqual(['all', 'note', 'activity', 'reminder', 'file', 'change']);
  });

  it('leaves every module change source to the module that registers it', () => {
    const changes = TIMELINE_TABS.find((tab) => tab.value === 'change');

    expect(changes?.sources).toEqual(['field_change', 'relation', 'merge']);
  });

  it('reaches every source key through exactly one tab', () => {
    for (const key of sourceKeys) {
      expect(TIMELINE_TABS.filter((tab) => tab.sources.includes(key))).toHaveLength(1);
    }
  });

  it('loads all sources on all and activities on activity', () => {
    expect(TIMELINE_TABS.find((tab) => tab.value === 'all')?.sources).toEqual([]);
    expect(TIMELINE_TABS.find((tab) => tab.value === 'activity')?.sources).toEqual(['activity']);
  });
});

describe('sourceLabels — the marker of a timeline entry', () => {
  it('gives every source that has one its own icon', () => {
    for (const key of ['note', 'reminder', 'file']) {
      expect(sourceIcon(key)).not.toBeNull();
    }
  });

  it('leaves a change without an icon so it stays a plain dot', () => {
    expect(sourceIcon('field_change')).toBeNull();
  });
});

describe('sourceLabels — German copy for every source', () => {
  it('gives every known source key its own label instead of echoing the key', () => {
    const labels = sourceKeys.map((key) => sourceLabel(key, t));

    expect(new Set(labels).size).toBe(sourceKeys.length);

    for (const [index, label] of labels.entries()) {
      expect(label.trim()).not.toBe('');
      expect(label).not.toBe(sourceKeys[index]);
      expect(label).not.toContain('_');
    }
  });

  it('falls back to one neutral label for a source key it does not know', () => {
    expect(() => sourceLabel('webinar', t)).not.toThrow();
    expect(sourceLabel('webinar', t)).toBe(sourceLabel('podcast_episode', t));
    expect(sourceLabel('webinar', t)).not.toBe('webinar');
    expect(sourceLabel('webinar', t).trim()).not.toBe('');
  });
});
