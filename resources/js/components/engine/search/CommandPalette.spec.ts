import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { axe } from 'vitest-axe';
import CommandPalette from '@/components/engine/search/CommandPalette.vue';
import { show } from '@/routes/engine/records';
import { setUrlDefaults } from '@/wayfinder';

setUrlDefaults({ activeTeam: 'nubos' });

const { visitMock } = vi.hoisted(() => ({ visitMock: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    router: { visit: visitMock },
    usePage: () => ({ props: {} }),
}));

if (typeof Element.prototype.scrollIntoView !== 'function') {
    Element.prototype.scrollIntoView = vi.fn();
}

const SEARCH_ENDPOINT = '/nubos/engine/search?q=';

const axeOptions = {
    runOnly: {
        type: 'tag' as const,
        values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'],
    },
    rules: { 'color-contrast': { enabled: false } },
};

interface WireRecord {
    id: string;
    title: string;
}

interface WireGroup {
    type: string;
    label: string;
    records: WireRecord[];
}

interface WirePayload {
    data: {
        groups: WireGroup[];
    };
}

function payload(groups: WireGroup[]): WirePayload {
    return { data: { groups } };
}

function jsonResponse(status: number, body: unknown): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    } as unknown as Response;
}

interface PendingFetch {
    url: string;
    resolve: (body: unknown, status?: number) => void;
}

function deferredFetch(): {
    mock: ReturnType<typeof vi.fn>;
    pending: PendingFetch[];
} {
    const pending: PendingFetch[] = [];

    const mock = vi.fn((url: string) => {
        return new Promise<Response>((resolvePromise) => {
            pending.push({
                url,
                resolve: (body, status = 200) =>
                    resolvePromise(jsonResponse(status, body)),
            });
        });
    });

    return { mock, pending };
}

function immediateFetch(body: unknown, status = 200): ReturnType<typeof vi.fn> {
    return vi.fn(() => Promise.resolve(jsonResponse(status, body)));
}

let wrapper: ReturnType<typeof mount> | null = null;

function mountPalette(): ReturnType<typeof mount> {
    wrapper = mount(CommandPalette, { attachTo: document.body });

    return wrapper;
}

function press(key: string, modifiers: KeyboardEventInit = {}): KeyboardEvent {
    const down = new KeyboardEvent('keydown', {
        key,
        cancelable: true,
        bubbles: true,
        ...modifiers,
    });
    window.dispatchEvent(down);

    const up = new KeyboardEvent('keyup', {
        key,
        cancelable: true,
        bubbles: true,
        ...modifiers,
    });
    window.dispatchEvent(up);

    return down;
}

async function settle(): Promise<void> {
    await vi.advanceTimersByTimeAsync(1);
    await flushPromises();
}

async function toggleCommandKey(): Promise<KeyboardEvent> {
    const event = press('k', { metaKey: true });
    await settle();

    return event;
}

function paletteOpen(): boolean {
    return document.querySelector('[data-command-palette]') !== null;
}

function commandInput(): HTMLInputElement {
    const input = document.querySelector<HTMLInputElement>(
        '[data-command-input]',
    );

    if (input === null) {
        throw new Error('command input not found — the palette must be open');
    }

    return input;
}

async function typeQuery(value: string): Promise<void> {
    const input = commandInput();
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
}

function groups(): Element[] {
    return Array.from(document.querySelectorAll('[data-command-group]'));
}

function groupHeadings(): string[] {
    return groups().map((group) => {
        const heading = group.querySelector('[data-command-group-heading]');

        return (heading?.textContent ?? '').trim();
    });
}

function groupItemIds(group: Element): string[] {
    return Array.from(group.querySelectorAll('[data-command-item]')).map(
        (item) => item.getAttribute('data-record-id') ?? '',
    );
}

function allItems(): Element[] {
    return Array.from(document.querySelectorAll('[data-command-item]'));
}

function decodedUrls(mock: ReturnType<typeof vi.fn>): string[] {
    return mock.mock.calls.map(([url]) => decodeURIComponent(String(url)));
}

beforeEach(() => {
    vi.useFakeTimers();
    visitMock.mockReset();
});

afterEach(() => {
    if (wrapper !== null) {
        wrapper.unmount();
        wrapper = null;
    }

    document.body.replaceChildren();
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('CommandPalette — ⌘K open/close (SC-8)', () => {
    it('opens on ⌘K and closes on a second ⌘K, and preventDefaults the shortcut', async () => {
        vi.stubGlobal('fetch', immediateFetch(payload([])));
        mountPalette();
        await settle();

        expect(paletteOpen()).toBe(false);

        const openEvent = await toggleCommandKey();
        expect(paletteOpen()).toBe(true);
        expect(openEvent.defaultPrevented).toBe(true);

        await toggleCommandKey();
        expect(paletteOpen()).toBe(false);
    });

    it('closes on Escape once open', async () => {
        vi.stubGlobal('fetch', immediateFetch(payload([])));
        mountPalette();
        await settle();

        await toggleCommandKey();
        expect(paletteOpen()).toBe(true);

        document.dispatchEvent(
            new KeyboardEvent('keydown', {
                key: 'Escape',
                cancelable: true,
                bubbles: true,
            }),
        );
        await settle();

        expect(paletteOpen()).toBe(false);
    });
});

describe('CommandPalette — debounced server search (R-9)', () => {
    it('fires exactly one debounced fetch to /engine/search only after the debounce window', async () => {
        const fetchMock = immediateFetch(payload([]));
        vi.stubGlobal('fetch', fetchMock);
        mountPalette();
        await settle();

        await toggleCommandKey();
        await typeQuery('acme');

        await vi.advanceTimersByTimeAsync(150);
        expect(fetchMock).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(120);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const url = decodeURIComponent(String(fetchMock.mock.calls[0][0]));
        expect(url.startsWith(SEARCH_ENDPOINT)).toBe(true);
        expect(url).toContain('acme');
    });
});

describe('CommandPalette — grouped results (D3.2)', () => {
    it('renders one CommandGroup heading per object type with items in server order', async () => {
        const fetchMock = immediateFetch(
            payload([
                {
                    type: 'company',
                    label: 'Company',
                    records: [
                        { id: 'rec-c1', title: 'Acme Inc' },
                        { id: 'rec-c2', title: 'Acme Labs' },
                    ],
                },
                {
                    type: 'person',
                    label: 'Person',
                    records: [{ id: 'rec-p1', title: 'Ada Acme' }],
                },
            ]),
        );
        vi.stubGlobal('fetch', fetchMock);
        mountPalette();
        await settle();

        await toggleCommandKey();
        await typeQuery('acme');
        await vi.advanceTimersByTimeAsync(250);
        await flushPromises();

        expect(groupHeadings()).toEqual(['Company', 'Person']);

        const [companyGroup, personGroup] = groups();
        expect(groupItemIds(companyGroup)).toEqual(['rec-c1', 'rec-c2']);
        expect(groupItemIds(personGroup)).toEqual(['rec-p1']);

        expect(companyGroup.textContent).toContain('Acme Inc');
        expect(personGroup.textContent).toContain('Ada Acme');
    });
});

describe('CommandPalette — sequence guard against out-of-order responses (R-9)', () => {
    it('renders the newest response and ignores an older one that resolves late', async () => {
        const { mock, pending } = deferredFetch();
        vi.stubGlobal('fetch', mock);
        mountPalette();
        await settle();

        await toggleCommandKey();

        await typeQuery('a');
        await vi.advanceTimersByTimeAsync(250);
        await flushPromises();

        await typeQuery('ab');
        await vi.advanceTimersByTimeAsync(250);
        await flushPromises();

        expect(pending.length).toBe(2);
        expect(decodedUrls(mock)[0]).toContain('/engine/search?q=a');
        expect(decodedUrls(mock)[1]).toContain('/engine/search?q=ab');

        pending[1].resolve(
            payload([
                {
                    type: 'company',
                    label: 'Beta',
                    records: [{ id: 'rec-b', title: 'Beta Hit' }],
                },
            ]),
        );
        await settle();

        expect(groupHeadings()).toEqual(['Beta']);

        pending[0].resolve(
            payload([
                {
                    type: 'company',
                    label: 'Alpha',
                    records: [{ id: 'rec-a', title: 'Alpha Hit' }],
                },
            ]),
        );
        await settle();

        expect(groupHeadings()).toEqual(['Beta']);
        expect(document.body.textContent).not.toContain('Alpha Hit');
    });
});

describe('CommandPalette — object-type label normalization', () => {
    it('shows the resolved group label, never the App\\Models FQCN', async () => {
        const fetchMock = immediateFetch(
            payload([
                {
                    type: 'App\\Models\\Company',
                    label: 'Company',
                    records: [{ id: 'rec-1', title: 'Acme Inc' }],
                },
            ]),
        );
        vi.stubGlobal('fetch', fetchMock);
        mountPalette();
        await settle();

        await toggleCommandKey();
        await typeQuery('acme');
        await vi.advanceTimersByTimeAsync(250);
        await flushPromises();

        expect(groupHeadings()).toEqual(['Company']);
        expect(document.body.textContent).not.toContain('App\\Models');
    });
});

describe('CommandPalette — grid-editor guard (R-13)', () => {
    it('does not open and does not preventDefault when a grid cell editor is focused', async () => {
        vi.stubGlobal('fetch', immediateFetch(payload([])));
        mountPalette();
        await settle();

        const editor = document.createElement('div');
        editor.className = 'ag-cell-inline-editing';
        const cellInput = document.createElement('input');
        editor.appendChild(cellInput);
        document.body.appendChild(editor);
        cellInput.focus();
        expect(document.activeElement).toBe(cellInput);

        const event = press('k', { metaKey: true });
        await settle();

        expect(paletteOpen()).toBe(false);
        expect(event.defaultPrevented).toBe(false);
    });
});

describe('CommandPalette — empty states', () => {
    it('shows CommandEmpty when a non-empty query returns zero results', async () => {
        const fetchMock = immediateFetch(payload([]));
        vi.stubGlobal('fetch', fetchMock);
        mountPalette();
        await settle();

        await toggleCommandKey();
        await typeQuery('nothingmatches');
        await vi.advanceTimersByTimeAsync(250);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(document.querySelector('[data-command-empty]')).not.toBeNull();
        expect(allItems()).toHaveLength(0);
    });

    it('shows neither results nor empty state and fires no fetch for an empty query', async () => {
        const fetchMock = immediateFetch(payload([]));
        vi.stubGlobal('fetch', fetchMock);
        mountPalette();
        await settle();

        await toggleCommandKey();
        await typeQuery('');
        await vi.advanceTimersByTimeAsync(250);
        await flushPromises();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(document.querySelector('[data-command-empty]')).toBeNull();
        expect(allItems()).toHaveLength(0);
    });
});

describe('CommandPalette — navigation to detail view (D3.2)', () => {
    it('visits the record detail route when a result item is selected', async () => {
        const fetchMock = immediateFetch(
            payload([
                {
                    type: 'company',
                    label: 'Company',
                    records: [
                        { id: 'rec-c1', title: 'Acme Inc' },
                        { id: 'rec-c2', title: 'Acme Labs' },
                    ],
                },
            ]),
        );
        vi.stubGlobal('fetch', fetchMock);
        mountPalette();
        await settle();

        await toggleCommandKey();
        await typeQuery('acme');
        await vi.advanceTimersByTimeAsync(250);
        await flushPromises();

        const item = document.querySelector<HTMLElement>(
            '[data-record-id="rec-c2"]',
        );
        expect(item).not.toBeNull();

        item?.dispatchEvent(
            new MouseEvent('click', { bubbles: true, cancelable: true }),
        );
        await settle();

        expect(visitMock).toHaveBeenCalledTimes(1);
        expect(visitMock).toHaveBeenCalledWith(show.url({ record: 'rec-c2' }));
    });
});

describe('CommandPalette — error surfacing (SC-11, no silent catch)', () => {
    it('shows a visible error state with a retry when the search rejects', async () => {
        const fetchMock = vi.fn(() =>
            Promise.reject(new Error('network down')),
        );
        vi.stubGlobal('fetch', fetchMock);
        mountPalette();
        await settle();

        await toggleCommandKey();
        await typeQuery('acme');
        await vi.advanceTimersByTimeAsync(250);
        await flushPromises();

        const errorEl = document.querySelector('[data-command-error]');
        expect(errorEl).not.toBeNull();
        expect((errorEl?.textContent ?? '').trim().length).toBeGreaterThan(0);
        expect(document.querySelector('[data-command-empty]')).toBeNull();
    });

    it('re-runs the query when the retry affordance is pressed', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        const fetchMock = vi.fn(() =>
            Promise.reject(new Error('network down')),
        );
        vi.stubGlobal('fetch', fetchMock);
        mountPalette();
        await settle();

        await toggleCommandKey();
        await typeQuery('acme');
        await vi.advanceTimersByTimeAsync(250);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(1);

        const retry = document.querySelector<HTMLElement>(
            '[data-command-retry]',
        );
        expect(retry).not.toBeNull();

        retry?.dispatchEvent(
            new MouseEvent('click', { bubbles: true, cancelable: true }),
        );
        await vi.advanceTimersByTimeAsync(250);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(2);
    });
});

describe('CommandPalette — focus restore on close (SC-8)', () => {
    it('returns focus to the element that was focused before opening', async () => {
        vi.stubGlobal('fetch', immediateFetch(payload([])));
        mountPalette();
        await settle();

        const trigger = document.createElement('button');
        trigger.type = 'button';
        document.body.appendChild(trigger);
        trigger.focus();
        expect(document.activeElement).toBe(trigger);

        await toggleCommandKey();
        expect(paletteOpen()).toBe(true);

        await toggleCommandKey();
        expect(paletteOpen()).toBe(false);

        expect(document.activeElement).toBe(trigger);

        trigger.remove();
    });
});

describe('CommandPalette — accessibility (SC-14 / WCAG 2.1 AA)', () => {
    it('has no axe violations with results rendered', async () => {
        vi.stubGlobal(
            'fetch',
            immediateFetch(
                payload([
                    {
                        type: 'company',
                        label: 'Company',
                        records: [{ id: 'rec-1', title: 'Acme Inc' }],
                    },
                ]),
            ),
        );
        mountPalette();
        await settle();

        await toggleCommandKey();
        await typeQuery('acme');
        await vi.advanceTimersByTimeAsync(250);
        await flushPromises();

        vi.useRealTimers();

        const results = await axe(document.body, axeOptions);
        expect(results.violations).toEqual([]);
    });
});
