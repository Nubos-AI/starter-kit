const FIELD_KEY_PREFIX = 'data.';

export interface ErrorResponse {
    message: string | null;
    errors: Record<string, string[]>;
}

export function readErrorBody(body: unknown): ErrorResponse {
    if (body === null || typeof body !== 'object') {
        return { message: null, errors: {} };
    }

    const root = body as Record<string, unknown>;
    const message = root.message;

    return {
        message: typeof message === 'string' && message !== '' ? message : null,
        errors: readErrors(root.errors),
    };
}

export function readBodyReason(body: unknown): string | null {
    const failure = readErrorBody(body);

    for (const messages of Object.values(failure.errors)) {
        const message = messages[0];

        if (message !== undefined) {
            return message;
        }
    }

    return failure.message;
}

export async function readErrorResponse(
    response: Response,
): Promise<ErrorResponse> {
    try {
        return readErrorBody(await response.json());
    } catch {
        return { message: null, errors: {} };
    }
}

export async function readErrorReason(
    response: Response,
    fallback: string,
): Promise<string> {
    return (await readErrorResponse(response)).message ?? fallback;
}

export function toFieldErrors(
    errors: Record<string, string[]>,
): Record<string, string> {
    const result: Record<string, string> = {};

    for (const [key, messages] of Object.entries(errors)) {
        const message = messages[0];

        if (message === undefined) {
            continue;
        }

        result[
            key.startsWith(FIELD_KEY_PREFIX)
                ? key.slice(FIELD_KEY_PREFIX.length)
                : key
        ] = message;
    }

    return result;
}

function readErrors(value: unknown): Record<string, string[]> {
    if (value === null || typeof value !== 'object' || Array.isArray(value)) {
        return {};
    }

    const result: Record<string, string[]> = {};

    for (const [key, messages] of Object.entries(
        value as Record<string, unknown>,
    )) {
        if (typeof messages === 'string') {
            result[key] = [messages];

            continue;
        }

        if (
            Array.isArray(messages) &&
            messages.every((entry) => typeof entry === 'string')
        ) {
            result[key] = messages;
        }
    }

    return result;
}
