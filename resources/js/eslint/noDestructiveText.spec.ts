import { RuleTester } from 'eslint';
import { describe, it } from 'vitest';
import vueParser from 'vue-eslint-parser';
import noDestructiveText from './noDestructiveText.js';

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

tester.run('no-destructive-text', noDestructiveText, {
  valid: [
    template('<InputError :message="error" />'),
    template('<InputError role="alert" :message="error" />'),
    template('<span class="text-destructive" aria-hidden="true">*</span>'),
    template('<p class="text-sm text-danger">{{ error }}</p>'),
    template('<p class="text-sm text-destructive-foreground">{{ t(\'hint\') }}</p>'),
  ],
  invalid: [
    {
      code: template('<p class="text-sm text-destructive">{{ error }}</p>'),
      errors: [{ messageId: 'destructive' }],
    },
    {
      code: template('<p v-if="error" class="text-destructive" role="alert" data-error>{{ error }}</p>'),
      errors: [{ messageId: 'destructive' }],
    },
    {
      code: template(
        '<p\n  v-if="form.errors.name"\n  class="mt-1 text-xs text-destructive"\n>\n  {{ form.errors.name }}\n</p>',
      ),
      errors: [{ messageId: 'destructive' }],
    },
  ],
});
