import { describe, expect, it } from 'vitest';
import { resolveGoalActionRefusal } from '@/types/goals';

describe('resolveGoalActionRefusal', () => {
    it.each([
        [
            'not_owner',
            'Nur die Person, die dieses Ziel angelegt hat, darf es ändern oder löschen.',
        ],
        [
            'not_visible',
            'Sie dürfen dieses Ziel nicht einsehen und deshalb auch nicht verwalten.',
        ],
        [
            'unknown_reason',
            'Dieses Ziel lässt sich mit Ihren Rechten nicht bearbeiten.',
        ],
    ])('names the German refusal for the server reason %s', (reason, text) => {
        expect(resolveGoalActionRefusal(reason)).toBe(text);
    });

    it('names no refusal while the server sent no reason', () => {
        expect(resolveGoalActionRefusal(null)).toBeUndefined();
    });
});
