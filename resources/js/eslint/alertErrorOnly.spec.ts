import { RuleTester } from 'eslint';
import { describe, it } from 'vitest';
import vueParser from 'vue-eslint-parser';
import alertErrorOnly from './alertErrorOnly.js';

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

tester.run('alert-error-only', alertErrorOnly, {
  valid: [
    template('<AlertError :message="error" />'),
    template('<AlertError :title="t(\'failed\')" :errors="errors" />'),
    template("<Alert><AlertTitle>{{ t('note') }}</AlertTitle></Alert>"),
    template('<Alert variant="warning"><AlertDescription>{{ t(\'careful\') }}</AlertDescription></Alert>'),
    template('<Button variant="destructive">{{ t(\'delete\') }}</Button>'),
  ],
  invalid: [
    {
      code: template('<Alert variant="destructive"><AlertDescription>{{ error }}</AlertDescription></Alert>'),
      errors: [{ messageId: 'alertError' }],
    },
    {
      code: template(
        '<Alert v-if="error" variant="destructive" data-error>\n  <AlertCircle class="size-4" />\n  <AlertTitle>{{ t(\'failed\') }}</AlertTitle>\n  <AlertDescription>{{ error }}</AlertDescription>\n</Alert>',
      ),
      errors: [{ messageId: 'alertError' }],
    },
  ],
});
