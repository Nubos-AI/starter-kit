import { RuleTester } from 'eslint';
import { describe, it } from 'vitest';
import vueParser from 'vue-eslint-parser';
import noDirectIntlFormat from './noDirectIntlFormat.js';

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

tester.run('no-direct-intl-format in script', noDirectIntlFormat, {
  valid: [
    'const label = n(total, { style: "percent" });',
    'const label = d(value, { dateStyle: "medium" });',
    'const label = formatLocalizedNumber(total);',
    'const plural = new Intl.PluralRules("de").select(2);',
    'const relative = new Intl.RelativeTimeFormat("de");',
    'const text = value.toString();',
    'const upper = text.toLocaleUpperCase("de");',
  ],
  invalid: [
    {
      code: 'const label = new Intl.NumberFormat("de").format(total);',
      errors: [{ messageId: 'intl' }],
    },
    {
      code: 'const label = new Intl.DateTimeFormat(locale, options).format(date);',
      errors: [{ messageId: 'intl' }],
    },
    {
      code: 'const label = Intl.NumberFormat(locale).format(total);',
      errors: [{ messageId: 'intl' }],
    },
    {
      code: 'const label = total.toLocaleString(locale);',
      errors: [{ messageId: 'toLocale' }],
    },
    {
      code: 'const label = new Date(value).toLocaleDateString(locale);',
      errors: [{ messageId: 'toLocale' }],
    },
    {
      code: 'const label = date.toLocaleTimeString(locale, { hour: "2-digit" });',
      errors: [{ messageId: 'toLocale' }],
    },
  ],
});

vueTester.run('no-direct-intl-format in templates', noDirectIntlFormat, {
  valid: ['<template><span>{{ n(total) }}</span></template>'],
  invalid: [
    {
      code: '<template><span>{{ total.toLocaleString() }}</span></template>',
      errors: [{ messageId: 'toLocale' }],
    },
    {
      code: '<template><span>{{ new Intl.NumberFormat(locale).format(total) }}</span></template>',
      errors: [{ messageId: 'intl' }],
    },
  ],
});
