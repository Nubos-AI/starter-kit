import { describe, expect, it } from 'vitest';
import { pageRegistry } from '@/shared/lib/pages';
import type { ModuleComponentLoader } from '@/shared/types/modules';

const loader: ModuleComponentLoader = () => Promise.resolve({ default: { name: 'Page' } });

describe('pageRegistry', () => {
  it('names a domain page by its path below pages', () => {
    const pages = pageRegistry({
      './domains/workspace-access/feature-authentication/pages/auth/Login.vue': loader,
    });

    expect(Object.keys(pages)).toEqual(['auth/Login']);
  });

  it('names a module page by its path below pages', () => {
    const pages = pageRegistry({
      '/vendor/nubos/charge/resources/js/domains/billing/feature-invoices/pages/charge/invoices/Index.vue': loader,
    });

    expect(Object.keys(pages)).toEqual(['charge/invoices/Index']);
  });

  it('keeps a nested folder called pages inside the page name', () => {
    const pages = pageRegistry({
      './domains/web-engagement/feature-sites/pages/crm/pages/Edit.vue': loader,
    });

    expect(Object.keys(pages)).toEqual(['crm/pages/Edit']);
  });

  it('refuses two files that claim the same page name', () => {
    expect(() =>
      pageRegistry({
        './domains/workspace-access/feature-authentication/pages/auth/Login.vue': loader,
        './domains/workspace-access/feature-users/pages/auth/Login.vue': loader,
      }),
    ).toThrow('Duplicate page [auth/Login].');
  });

  it('ignores a file outside a pages folder', () => {
    const pages = pageRegistry({
      './domains/workspace-access/feature-auth/ui/Login.vue': loader,
    });

    expect(pages).toEqual({});
  });
});
