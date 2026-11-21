import { RuleTester } from 'eslint';
import { describe, it } from 'vitest';
import vueParser from 'vue-eslint-parser';
import inlineEmptyState from './inlineEmptyState.js';

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

tester.run('inline-empty-state', inlineEmptyState, {
  valid: [
    template('<EmptyState v-if="rows.length === 0" variant="inline" :description="t(\'empty\')" />'),
    template('<p v-if="rows.length === 0" class="text-sm">{{ t(\'empty\') }}</p>'),
    template('<p v-if="rows.length > 0" class="text-sm text-muted-foreground">{{ t(\'count\') }}</p>'),
    template('<p class="text-sm text-muted-foreground">{{ t(\'hint\') }}</p>'),
    template('<div v-if="rows.length === 0" class="text-sm text-muted-foreground">{{ t(\'empty\') }}</div>'),
    template('<p v-if="isEmpty" class="text-sm text-muted-foreground">{{ t(\'empty\') }}</p>'),
  ],
  invalid: [
    {
      code: template('<p v-if="rows.length === 0" class="text-sm text-muted-foreground">{{ t(\'empty\') }}</p>'),
      errors: [{ messageId: 'inline' }],
    },
    {
      code: template('<p v-if="items.length === 0" class="text-muted-foreground">{{ t(\'empty\') }}</p>'),
      errors: [{ messageId: 'inline' }],
    },
    {
      code: template(
        '<p\n  v-if="!loading && props.entries.length === 0"\n  class="py-6 text-center text-xs text-muted-foreground"\n  data-empty\n>\n  {{ t(\'empty\') }}\n</p>',
      ),
      errors: [{ messageId: 'inline' }],
    },
    {
      code: template(
        '<ul v-if="rows.length > 0"><li /></ul><p v-else-if="rows.length === 0" class="text-sm text-muted-foreground">{{ t(\'empty\') }}</p>',
      ),
      errors: [{ messageId: 'inline' }],
    },
  ],
});
