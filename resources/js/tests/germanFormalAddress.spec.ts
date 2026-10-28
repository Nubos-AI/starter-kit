import { describe, expect, it } from 'vitest';

const INFORMAL_PRONOUN =
    /\b(Du|du|Dir|dir|Dich|dich|Dein|dein|Deine|deine|Deinem|deinem|Deinen|deinen|Deiner|deiner|Deines|deines)\b/;

const INFORMAL_IMPERATIVE =
    /\b(Wähle|wähle|Klicke|klicke|Trage|trage|Prüfe|prüfe|Beachte|beachte|Ziehe|ziehe|Füge|füge|Nutze|nutze|Erstelle|erstelle|Öffne|öffne|Lege|lege|Gib|gib|Versuche|versuche|Lade|lade|Speichere|speichere|Lösche|lösche|Sende|sende|Bearbeite|bearbeite|Ändere|ändere|Wiederhole|wiederhole|Kopiere|kopiere|Verschiebe|verschiebe|Melde|melde|Achte|achte|Passe|passe|Fasse|fasse|Setze|setze|Benenne|benenne|Definiere|definiere|Ergänze|ergänze|Entferne|entferne|Aktiviere|aktiviere|Deaktiviere|deaktiviere|Starte|starte|Hinterlege|hinterlege|Ordne|ordne|Vergib|vergib|Wechsle|wechsle|Halte|halte|Schreibe|schreibe|Denke|denke|Vergiss|vergiss|Warte|warte|Schau|schau|Ziehe|ziehe|Tippe|tippe|Markiere|markiere|Bestätige|bestätige|Verwende|verwende|Beginne|beginne|Beende|beende|Sende|sende)\b/;

const sources = import.meta.glob('../**/*.{ts,vue}', {
    eager: true,
    query: '?raw',
    import: 'default',
}) as Record<string, string>;

function isShipped(path: string): boolean {
    return (
        !path.startsWith('../actions/') &&
        !path.startsWith('../routes/') &&
        !path.startsWith('../wayfinder') &&
        !path.startsWith('../tests/') &&
        !path.endsWith('.spec.ts')
    );
}

function offenders(pattern: RegExp): string[] {
    return Object.entries(sources)
        .filter(([path]) => isShipped(path))
        .flatMap(([path, source]) =>
            source
                .split('\n')
                .map((line, index) => ({ line, no: index + 1 }))
                .filter((entry) => pattern.test(entry.line))
                .map((entry) => `${path.replace('..', '')}:${entry.no}`),
        );
}

describe('the German copy addresses the user formally', () => {
    it('reads shipped sources at all', () => {
        expect(Object.keys(sources).filter(isShipped).length).toBeGreaterThan(
            100,
        );
    });

    it('carries no informal pronoun', () => {
        expect(offenders(INFORMAL_PRONOUN)).toEqual([]);
    });

    it('carries no informal imperative', () => {
        expect(offenders(INFORMAL_IMPERATIVE)).toEqual([]);
    });

    it('recognises both forms and leaves the formal ones alone', () => {
        expect(INFORMAL_PRONOUN.test('Dir fehlt die Berechtigung.')).toBe(true);
        expect(INFORMAL_IMPERATIVE.test('Wähle einen Datensatz.')).toBe(true);
        expect(INFORMAL_PRONOUN.test('Ihnen fehlt die Berechtigung.')).toBe(
            false,
        );
        expect(INFORMAL_PRONOUN.test('einen Entwurf, den du bearbeitest')).toBe(
            true,
        );
        expect(INFORMAL_IMPERATIVE.test('Wählen Sie einen Datensatz.')).toBe(
            false,
        );
        expect(INFORMAL_IMPERATIVE.test('Speichern')).toBe(false);
        expect(
            INFORMAL_IMPERATIVE.test(
                'Die Suche konnte nicht ausgeführt werden.',
            ),
        ).toBe(false);
        expect(INFORMAL_IMPERATIVE.test('Objekte durchsuchen …')).toBe(false);
    });
});
