import { afterEach, describe, expect, it, vi } from 'vitest';

const { successSpy } = vi.hoisted(() => ({ successSpy: vi.fn() }));

vi.mock('vue-sonner', () => ({ toast: { success: successSpy } }));

import { recordToast } from '@/lib/recordToast';

afterEach(() => {
    vi.clearAllMocks();
});

describe('recordToast', () => {
    it('raises a plain success toast so every toast looks the same', () => {
        recordToast('create');

        expect(successSpy).toHaveBeenCalledWith('Datensatz angelegt', {
            description: undefined,
        });
    });

    it('pluralizes the title when a count above one is passed', () => {
        recordToast('delete', { count: 5 });

        expect(successSpy.mock.calls[0][0]).toBe('5 Datensätze gelöscht');
    });

    it('reports a deletion as done, not as an error', () => {
        recordToast('delete');

        expect(successSpy.mock.calls[0][0]).toBe('Datensatz gelöscht');
    });

    it('forwards a description onto the toast', () => {
        recordToast('edit', { description: 'Acme GmbH' });

        expect(successSpy).toHaveBeenCalledWith('Datensatz aktualisiert', {
            description: 'Acme GmbH',
        });
    });
});
