import { RuleTester } from 'eslint';
import { describe, it } from 'vitest';
import vueParser from 'vue-eslint-parser';
import skeletonList from './skeletonList.js';

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

tester.run('skeleton-list', skeletonList, {
  valid: [
    template('<SkeletonList :count="3" size="md" />'),
    template('<Skeleton class="h-72 w-full" />'),
    template("<Skeleton :class=\"wide ? 'h-10 w-full' : 'h-10 w-24'\" />"),
  ],
  invalid: [
    {
      code: template('<Skeleton v-for="line in 3" :key="line" class="h-4 w-full" />'),
      errors: [{ messageId: 'list' }],
    },
    {
      code: template('<Skeleton class="h-10 w-full animate-pulse" />'),
      errors: [{ messageId: 'pulse' }],
    },
    {
      code: template("<Skeleton :class=\"['h-10', 'animate-pulse']\" />"),
      errors: [{ messageId: 'pulse' }],
    },
    {
      code: template('<Skeleton v-for="index in 6" :key="index" class="h-28 w-full animate-pulse" />'),
      errors: [{ messageId: 'list' }, { messageId: 'pulse' }],
    },
  ],
});
