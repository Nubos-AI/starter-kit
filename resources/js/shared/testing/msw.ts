import { http as inertiaHttp } from '@inertiajs/core';
import { delay, http, HttpResponse } from 'msw';
import type { JsonBodyType } from 'msw';
import { setupServer } from 'msw/node';
import type { SetupServer } from 'msw/node';
import { afterAll, afterEach, beforeAll, expect, vi } from 'vitest';

type RouteMethod = 'get' | 'post' | 'put' | 'patch' | 'delete' | 'head' | 'options';

export interface ServedRouteDefinition {
  url: string;
  method: RouteMethod;
}

export interface RecordedRequest {
  method: string;
  url: URL;
  headers: Headers;
  body: unknown;
  signal: AbortSignal;
}

export interface ServeOptions {
  status?: number;
  headers?: Record<string, string>;
  latencyMs?: number;
  held?: boolean;
  once?: boolean;
}

export interface ServedRoute {
  requests: RecordedRequest[];
  lastRequest: () => RecordedRequest | undefined;
  release: () => void;
}

export interface RequestLog {
  requests: Request[];
  pendingCount: () => number;
  settle: () => Promise<void>;
}

export type ValidationErrors = Record<string, string[]>;

interface SharedMswServer {
  server: SetupServer;
  listeners: number;
}

const sharedServerKey = Symbol.for('nubos.tests.mswServer');

function sharedServer(): SharedMswServer {
  const existing: unknown = Reflect.get(globalThis, sharedServerKey);

  if (isSharedServer(existing)) {
    return existing;
  }

  const created: SharedMswServer = { server: setupServer(), listeners: 0 };
  Reflect.set(globalThis, sharedServerKey, created);

  return created;
}

function isSharedServer(value: unknown): value is SharedMswServer {
  return typeof value === 'object' && value !== null && 'server' in value;
}

export const mswServer: SetupServer = sharedServer().server;

export function installMswLifecycle(): void {
  const shared = sharedServer();

  beforeAll(() => {
    if (shared.listeners === 0) {
      shared.server.listen({ onUnhandledRequest: 'error' });
    }

    shared.listeners++;
  });

  afterEach(() => {
    shared.server.resetHandlers();
    shared.server.events.removeAllListeners();
  });

  afterAll(() => {
    shared.listeners--;

    if (shared.listeners === 0) {
      shared.server.close();
    }
  });
}

function pathOf(route: ServedRouteDefinition): string {
  return route.url.split('?')[0];
}

async function readBody(request: Request): Promise<unknown> {
  const contentType = request.headers.get('content-type') ?? '';

  const text = await request.text();

  if (contentType.includes('multipart/form-data')) {
    return text;
  }

  if (text === '') {
    return null;
  }

  try {
    return JSON.parse(text);
  } catch {
    return text;
  }
}

function createGate(held: boolean): {
  wait: () => Promise<void>;
  release: () => void;
} {
  if (!held) {
    return { wait: () => Promise.resolve(), release: () => undefined };
  }

  let open: () => void = () => undefined;
  const opened = new Promise<void>((resolve) => {
    open = resolve;
  });

  return { wait: () => opened, release: () => open() };
}

function register(route: ServedRouteDefinition, options: ServeOptions, respond: () => Response): ServedRoute {
  const requests: RecordedRequest[] = [];
  const gate = createGate(options.held === true);

  mswServer.use(
    http[route.method](
      pathOf(route),
      async ({ request }) => {
        requests.push({
          method: request.method,
          url: new URL(request.url),
          headers: request.headers,
          body: await readBody(request.clone()),
          signal: request.signal,
        });

        await gate.wait();

        if (options.latencyMs !== undefined) {
          await delay(options.latencyMs);
        }

        return respond();
      },
      { once: options.once === true },
    ),
  );

  return {
    requests,
    lastRequest: () => requests.at(-1),
    release: gate.release,
  };
}

export function serveJson(route: ServedRouteDefinition, body: JsonBodyType, options: ServeOptions = {}): ServedRoute {
  return register(route, options, () =>
    HttpResponse.json(body, {
      status: options.status ?? 200,
      headers: options.headers,
    }),
  );
}

export function serveEmpty(route: ServedRouteDefinition, options: ServeOptions = {}): ServedRoute {
  return register(
    route,
    options,
    () =>
      new HttpResponse(null, {
        status: options.status ?? 204,
        headers: options.headers,
      }),
  );
}

export function serveValidationError(
  route: ServedRouteDefinition,
  errors: ValidationErrors,
  options: ServeOptions = {},
): ServedRoute {
  const firstMessage = Object.values(errors)[0]?.[0] ?? '';

  return serveJson(route, { message: firstMessage, errors }, { ...options, status: 422 });
}

export function serveServerError(
  route: ServedRouteDefinition,
  body: JsonBodyType = { message: 'Server Error' },
  options: ServeOptions = {},
): ServedRoute {
  return serveJson(route, body, {
    ...options,
    status: options.status ?? 500,
  });
}

export function serveNetworkError(route: ServedRouteDefinition, options: ServeOptions = {}): ServedRoute {
  return register(route, options, () => HttpResponse.error());
}

export function recordEveryRequest(): RequestLog {
  const requests: Request[] = [];
  let pending = 0;

  mswServer.events.on('request:start', ({ request }) => {
    requests.push(request);
    pending++;
  });
  mswServer.events.on('request:end', () => {
    pending--;
  });

  return {
    requests,
    pendingCount: () => pending,
    settle: () => vi.waitFor(() => expect(pending).toBe(0)),
  };
}

export function trackInertiaRequestSignals(): AbortSignal[] {
  const signals: AbortSignal[] = [];
  const client = inertiaHttp.getClient();
  const request = client.request.bind(client);

  vi.spyOn(client, 'request').mockImplementation((config) => {
    if (config.signal !== undefined) {
      signals.push(config.signal);
    }

    return request(config);
  });

  return signals;
}
