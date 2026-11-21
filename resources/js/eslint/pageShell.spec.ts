import { RuleTester } from 'eslint';
import { describe, it } from 'vitest';
import vueParser from 'vue-eslint-parser';
import pageShell from './pageShell.js';

RuleTester.describe = describe;
RuleTester.it = it;
RuleTester.itOnly = it.only;

const tester = new RuleTester({
  languageOptions: {
    parser: vueParser,
    ecmaVersion: 2022,
    sourceType: 'module',
  },
});

function template(markup: string): string {
  return `<template>${markup}</template>`;
}

tester.run('page-shell', pageShell, {
  valid: [
    template('<PageShell :title="title" :description="description"><Card /></PageShell>'),
    template('<div class="flex flex-col gap-6 p-3"><Card /></div>'),
    template('<div class="flex flex-col gap-4"><Heading :title="title" /></div>'),
    template('<Card><div class="flex flex-col gap-6 p-3"><Heading :title="title" /></div></Card>'),
    template('<main class="flex flex-col gap-6 p-3"><Heading :title="title" /></main>'),
    template('<div class="flex h-full min-h-0 flex-col gap-6 p-3"><Heading :title="title" /><SectionNav /></div>'),
  ],
  invalid: [
    {
      code: template(
        '<div class="flex flex-col gap-6 p-3"><Head :title="title" /><Heading variant="small" :title="title" /></div>',
      ),
      errors: [{ messageId: 'shell' }],
    },
    {
      code: template(
        '<Head :title="title" /><div class="flex flex-col gap-6 p-3" data-detail><Heading :title="title" /></div>',
      ),
      errors: [{ messageId: 'shell' }],
    },
    {
      code: template(
        '<div class="flex flex-col gap-6 p-3"><div class="flex items-start justify-between gap-4"><Heading :title="title" /><Button /></div></div>',
      ),
      errors: [{ messageId: 'shell' }],
    },
  ],
});
