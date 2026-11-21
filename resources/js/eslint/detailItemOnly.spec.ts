import { RuleTester } from 'eslint';
import { describe, it } from 'vitest';
import vueParser from 'vue-eslint-parser';
import detailItemOnly from './detailItemOnly.js';

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

tester.run('detail-item-only', detailItemOnly, {
  valid: [
    template(
      '<DetailList compact><DetailItem :label="t(\'net\')" variant="summary">{{ net }}</DetailItem></DetailList>',
    ),
    template('<dl class="grid gap-2"><DetailItem :label="t(\'net\')">{{ net }}</DetailItem></dl>'),
    template('<dd>{{ value }}</dd>'),
  ],
  invalid: [
    {
      code: template(
        '<dl class="grid gap-2"><div class="flex justify-between gap-3"><dt class="text-muted-foreground">{{ t(\'net\') }}</dt><dd>{{ net }}</dd></div></dl>',
      ),
      errors: [{ messageId: 'detailItem' }],
    },
    {
      code: template('<dl class="grid grid-cols-[1fr_auto]"><dt>{{ t(\'total\') }}</dt><dd>{{ total }}</dd></dl>'),
      errors: [{ messageId: 'detailItem' }],
    },
  ],
});
