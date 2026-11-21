import { describe, expect, it } from 'vitest';
import {
  isErrorBag,
  readBodyReason,
  readErrorBody,
  readErrorReason,
  readJsonBody,
  readErrorResponse,
  toFieldErrors,
} from '@/shared/lib/errorResponse';

function jsonResponse(status: number, body: unknown): Response {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: async () => body,
  } as unknown as Response;
}

function brokenResponse(status: number): Response {
  return {
    ok: false,
    status,
    json: async () => {
      throw new SyntaxError('not json');
    },
  } as unknown as Response;
}

describe('readErrorBody', () => {
  it('keeps the summary message and the field errors of a Laravel refusal', () => {
    expect(
      readErrorBody({
        message: 'Die Daten sind ungültig.',
        errors: { name: ['Der Name fehlt.'] },
      }),
    ).toEqual({
      message: 'Die Daten sind ungültig.',
      errors: { name: ['Der Name fehlt.'] },
    });
  });

  it('accepts a single string as a field error', () => {
    expect(readErrorBody({ errors: { name: 'Der Name fehlt.' } })).toEqual({
      message: null,
      errors: { name: ['Der Name fehlt.'] },
    });
  });

  it('reports nothing for a body that is not an object', () => {
    expect(readErrorBody('nope')).toEqual({ message: null, errors: {} });
    expect(readErrorBody(null)).toEqual({ message: null, errors: {} });
  });
});

describe('readBodyReason', () => {
  it('prefers the field error over the summary message', () => {
    expect(
      readBodyReason({
        message: 'Die Daten sind ungültig.',
        errors: { name: ['Der Name ist schon vergeben.'] },
      }),
    ).toBe('Der Name ist schon vergeben.');
  });

  it('falls back to the summary message when no field error names a cause', () => {
    expect(readBodyReason({ message: 'Sie dürfen das nicht.' })).toBe('Sie dürfen das nicht.');
  });

  it('reports nothing when the body carries neither', () => {
    expect(readBodyReason({})).toBeNull();
    expect(readBodyReason(null)).toBeNull();
  });
});

describe('readErrorResponse and readErrorReason', () => {
  it('reads the refusal body of a response', async () => {
    await expect(readErrorResponse(jsonResponse(422, { message: 'Zu lang.', errors: {} }))).resolves.toEqual({
      message: 'Zu lang.',
      errors: {},
    });
  });

  it('falls back when the body cannot be read at all', async () => {
    await expect(readErrorReason(brokenResponse(403), 'Standardsatz.')).resolves.toBe('Standardsatz.');
  });

  it('returns the server message when there is one', async () => {
    await expect(readErrorReason(jsonResponse(403, { message: 'Kein Zugriff.' }), 'Standardsatz.')).resolves.toBe(
      'Kein Zugriff.',
    );
  });
});

describe('toFieldErrors', () => {
  it('keeps the first message per field and strips the data. prefix', () => {
    expect(
      toFieldErrors({
        'data.titel': ['Zu lang.', 'Und noch etwas.'],
        owner_id: ['Unbekannt.'],
      }),
    ).toEqual({ titel: 'Zu lang.', owner_id: 'Unbekannt.' });
  });

  it('drops a field without any message', () => {
    expect(toFieldErrors({ titel: [] })).toEqual({});
  });
});

describe('readJsonBody', () => {
  it('reads the parsed body of a response', async () => {
    await expect(readJsonBody(new Response(JSON.stringify({ id: 1 })))).resolves.toEqual({ id: 1 });
  });

  it('answers null for a body that is no JSON', async () => {
    await expect(readJsonBody(new Response('<html>'))).resolves.toBeNull();
  });
});

describe('isErrorBag', () => {
  it('accepts the error object of a page', () => {
    expect(isErrorBag({ name: 'Pflichtfeld.' })).toBe(true);
  });

  it('refuses a missing error object', () => {
    expect(isErrorBag(undefined)).toBe(false);
    expect(isErrorBag(null)).toBe(false);
  });
});
