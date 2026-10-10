import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    SEARCH_DEBOUNCE_MS,
    useRecordSearch,
} from '@/composables/useRecordSearch';

beforeEach(() => {
    vi.useFakeTimers();
});

describe('useRecordSearch', () => {
    it('searches within a blink of the last keystroke', () => {
        const search = useRecordSearch();

        search.setDraft('mus');
        vi.advanceTimersByTime(SEARCH_DEBOUNCE_MS - 1);

        expect(search.term.value).toBeNull();

        vi.advanceTimersByTime(1);

        expect(search.term.value).toBe('mus');
    });

    it('coalesces a burst of keystrokes into a single search', () => {
        const search = useRecordSearch();

        search.setDraft('mus');
        vi.advanceTimersByTime(SEARCH_DEBOUNCE_MS - 50);
        search.setDraft('muster');
        vi.advanceTimersByTime(SEARCH_DEBOUNCE_MS - 50);

        expect(search.term.value).toBeNull();

        vi.advanceTimersByTime(50);

        expect(search.term.value).toBe('muster');
    });

    it('keeps the wait short enough to feel immediate', () => {
        expect(SEARCH_DEBOUNCE_MS).toBeLessThanOrEqual(200);
    });

    it('refuses a term shorter than three characters', () => {
        const search = useRecordSearch();

        search.setDraft('mu');
        vi.advanceTimersByTime(SEARCH_DEBOUNCE_MS);

        expect(search.term.value).toBeNull();
    });

    it('drops the term the moment the field is emptied, without waiting', () => {
        const search = useRecordSearch();

        search.setDraft('muster');
        vi.advanceTimersByTime(SEARCH_DEBOUNCE_MS);

        expect(search.term.value).toBe('muster');

        search.setDraft('');

        expect(search.term.value).toBeNull();
        expect(search.draft.value).toBe('');
    });

    it('never revives a pending term after the field was emptied', () => {
        const search = useRecordSearch();

        search.setDraft('muster');
        search.clear();
        vi.advanceTimersByTime(SEARCH_DEBOUNCE_MS * 2);

        expect(search.term.value).toBeNull();
    });

    it('trims what the user typed around the term', () => {
        const search = useRecordSearch();

        search.setDraft('  muster  ');
        vi.advanceTimersByTime(SEARCH_DEBOUNCE_MS);

        expect(search.term.value).toBe('muster');
    });
});
