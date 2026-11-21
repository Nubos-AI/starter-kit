import { RuleTester } from 'eslint';
import { describe, it } from 'vitest';
import vueParser from 'vue-eslint-parser';
import formFieldFrame from './formFieldFrame.js';

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

tester.run('form-field-frame', formFieldFrame, {
  valid: [
    template('<FormField :label="t(\'name\')" for="name"><Input id="name" /></FormField>'),
    template(
      '<div class="flex items-center gap-3"><Label for="active">{{ t(\'active\') }}</Label><Switch id="active" /></div>',
    ),
    template(
      '<div class="flex items-center gap-2"><Checkbox id="flag" /><Label for="flag">{{ t(\'flag\') }}</Label></div>',
    ),
    template('<div class="grid gap-4 sm:grid-cols-2"><FormField for="a" /><FormField for="b" /></div>'),
    template('<div class="grid gap-2"><p>{{ t(\'intro\') }}</p><Label for="x">{{ t(\'x\') }}</Label></div>'),
    template('<fieldset class="grid gap-2"><Label>{{ t(\'group\') }}</Label></fieldset>'),
    template('<FormField for="name" :error="errors.name"><Input id="name" /></FormField>'),
    template(
      '<FormField for="rows"><div class="grid gap-2"><Input id="rows" /><InputError :message="rowError" /></div></FormField>',
    ),
    template('<div class="grid gap-4"><FormField for="a" /><InputError :message="formError" /></div>'),
  ],
  invalid: [
    {
      code: template('<div class="grid gap-2"><Label for="name">{{ t(\'name\') }}</Label><Input id="name" /></div>'),
      errors: [{ messageId: 'frame' }],
    },
    {
      code: template(
        '<div class="grid content-start gap-2 sm:max-w-sm"><Label for="name">{{ t(\'name\') }}</Label></div>',
      ),
      errors: [{ messageId: 'frame' }],
    },
    {
      code: template(
        '<div class="flex w-44 flex-col gap-1"><Label for="from">{{ t(\'from\') }}</Label><Input id="from" /></div>',
      ),
      errors: [{ messageId: 'frame' }],
    },
    {
      code: template(
        '<div class="space-y-2">\n  <Label for="note">{{ t(\'note\') }}</Label>\n  <Textarea id="note" />\n</div>',
      ),
      errors: [{ messageId: 'frame' }],
    },
    {
      code: template('<FormField for="name"><Input id="name" /><InputError :message="errors.name" /></FormField>'),
      errors: [{ messageId: 'errorInSlot' }],
    },
    {
      code: template(
        '<FormField for="body" :label="t(\'body\')">\n  <Textarea id="body" />\n  <InputError v-if="bodyError" :message="bodyError" />\n</FormField>',
      ),
      errors: [{ messageId: 'errorInSlot' }],
    },
  ],
});
