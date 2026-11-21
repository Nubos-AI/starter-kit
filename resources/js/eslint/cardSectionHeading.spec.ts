import { RuleTester } from 'eslint';
import { describe, it } from 'vitest';
import vueParser from 'vue-eslint-parser';
import cardSectionHeading from './cardSectionHeading.js';

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

tester.run('card-section-heading', cardSectionHeading, {
  valid: [
    template("<CardSection :title=\"t('one_time')\"><p>{{ t('body') }}</p></CardSection>"),
    template('<h3 class="text-sm font-medium">{{ t(\'small\') }}</h3>'),
    template('<h2 class="text-lg font-bold">{{ t(\'month\') }}</h2>'),
  ],
  invalid: [
    {
      code: template('<section><h3 class="text-lg font-bold">{{ t(\'one_time\') }}</h3></section>'),
      errors: [{ messageId: 'cardSection' }],
    },
  ],
});
