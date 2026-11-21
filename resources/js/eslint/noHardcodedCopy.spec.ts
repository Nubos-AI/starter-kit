import { RuleTester } from 'eslint';
import { describe, it } from 'vitest';
import vueParser from 'vue-eslint-parser';
import noHardcodedCopy from './noHardcodedCopy.js';

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

tester.run('no-hardcoded-copy in script', noHardcodedCopy, {
  valid: [
    "toast.success(t('i18n.components.export.done'));",
    "const options = [{ value: 'sum', label: 'i18n.types.rollups.sum' }];",
    "const options = [{ value: 'sum', label: 'application::i18n.start.title' }];",
    "import Start from './Start.vue';",
    "const column = { field: 'record_number', type: 'number' };",
    "const classes = 'grid grid-cols-5 gap-4';",
    "Log('The tenant could not be resolved.');",
    "const state = { title: 'small' };",
  ],
  invalid: [
    {
      code: "toast.info('Import abgebrochen.');",
      errors: [{ messageId: 'german' }],
    },
    {
      code: "toast.success('Export ready.');",
      errors: [{ messageId: 'toast' }],
    },
    {
      code: "const options = [{ value: 'sum', label: 'Sum' }];",
      errors: [{ messageId: 'property' }],
    },
    {
      code: "const options = [{ value: 'avg', label: 'Average value' }];",
      errors: [{ messageId: 'property' }],
    },
    {
      code: "const updated = { updated_at: 'Letzte Änderung' };",
      errors: [{ messageId: 'german' }],
    },
    {
      code: 'const message = `Für ${count} Einträge`;',
      errors: [{ messageId: 'german' }],
    },
    {
      code: "const column = { headerName: 'Created at' };",
      errors: [{ messageId: 'property' }],
    },
  ],
});

vueTester.run('no-hardcoded-copy in templates', noHardcodedCopy, {
  valid: [
    '<template><Button :aria-label="t(\'i18n.save\')" /></template>',
    "<template><div :class=\"isOpen ? 'grid grid-cols-2' : 'flex'\" /></template>",
  ],
  invalid: [
    {
      code: '<template><Button :aria-label="\'Close dialog\'" /></template>',
      errors: [{ messageId: 'attribute' }],
    },
    {
      code: "<template><Badge :label=\"isDone ? 'Erledigt' : 'Offen'\" /></template>",
      errors: [{ messageId: 'attribute' }, { messageId: 'attribute' }],
    },
  ],
});
