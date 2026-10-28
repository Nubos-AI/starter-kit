export interface BuildHeadersOptions {
    hasBody?: boolean;
}

export function readXsrfToken(): string | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : null;
}

export function buildHeaders(
    options: BuildHeadersOptions = {},
): Record<string, string> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    if (options.hasBody === true) {
        headers['Content-Type'] = 'application/json';
    }

    const token = readXsrfToken();

    if (token !== null) {
        headers['X-XSRF-TOKEN'] = token;
    }

    return headers;
}
