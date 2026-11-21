import { describe, expect, it, vi } from 'vitest';
import { submitCreateOrUpdate } from './formSubmit';

function fakeForm() {
  const payloads: unknown[] = [];
  const request = { put: vi.fn(), post: vi.fn() };
  const form = {
    transform: (build: () => Record<string, unknown>) => {
      payloads.push(build());

      return request;
    },
  };

  return { form, request, payloads };
}

describe('submitCreateOrUpdate', () => {
  const handlers = { onSuccess: vi.fn(), onError: vi.fn() };

  it('updates the edited record at its address', () => {
    const { form, request, payloads } = fakeForm();

    submitCreateOrUpdate(form, () => ({ name: 'A' }), {
      updateUrl: '/goals/1',
      storeUrl: '/goals',
      ...handlers,
    });

    expect(request.put).toHaveBeenCalledWith('/goals/1', {
      preserveScroll: true,
      ...handlers,
    });
    expect(request.post).not.toHaveBeenCalled();
    expect(payloads).toEqual([{ name: 'A' }]);
  });

  it('stores a new record without an edit address', () => {
    const { form, request } = fakeForm();

    submitCreateOrUpdate(form, () => ({ name: 'B' }), {
      updateUrl: null,
      storeUrl: '/goals',
      ...handlers,
    });

    expect(request.post).toHaveBeenCalledWith('/goals', {
      preserveScroll: true,
      ...handlers,
    });
    expect(request.put).not.toHaveBeenCalled();
  });
});
