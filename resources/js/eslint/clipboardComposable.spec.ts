import { RuleTester } from 'eslint';
import { describe, it } from 'vitest';
import vueParser from 'vue-eslint-parser';
import clipboardComposable from './clipboardComposable.js';

RuleTester.describe = describe;
RuleTester.it = it;
RuleTester.itOnly = it.only;

const tester = new RuleTester({
  languageOptions: { ecmaVersion: 2022, sourceType: 'module' },
});

const vueTester = new RuleTester({
  languageOptions: {
    parser: vueParser,
    ecmaVersion: 2022,
    sourceType: 'module',
  },
});

tester.run('clipboard-composable in script', clipboardComposable, {
  valid: [
    'import { useClipboard } from "@/shared/composables/useClipboard"; const { copy } = useClipboard();',
    'import { useTimeoutFn } from "@vueuse/core";',
    'const clipboard = event.clipboardData?.getData("text");',
  ],
  invalid: [
    {
      code: 'async function copy(text) { await navigator.clipboard.writeText(text); }',
      errors: [{ messageId: 'navigator' }],
    },
    {
      code: 'void navigator.clipboard.writeText(url);',
      errors: [{ messageId: 'navigator' }],
    },
    {
      code: 'import { useClipboard, useTimeoutFn } from "@vueuse/core";',
      errors: [{ messageId: 'vueuse' }],
    },
  ],
});

vueTester.run('clipboard-composable in a template', clipboardComposable, {
  valid: ['<template><Button @click="copy(url)">{{ t(\'copy\') }}</Button></template>'],
  invalid: [
    {
      code: '<template><Button @click="navigator.clipboard.writeText(url)">{{ t(\'copy\') }}</Button></template>',
      errors: [{ messageId: 'navigator' }],
    },
    {
      code: '<script setup lang="ts">\nimport { useClipboard } from \'@vueuse/core\';\n</script>\n<template><span /></template>',
      errors: [{ messageId: 'vueuse' }],
    },
  ],
});
