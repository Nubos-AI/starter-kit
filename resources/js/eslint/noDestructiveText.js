import { hasClassToken } from './templateAttributes.js';

/** @type {import('eslint').Rule.RuleModule} */
const noDestructiveText = {
  meta: {
    type: 'problem',
    docs: {
      description: 'An error sentence is rendered through <InputError>, never as a hand-coloured paragraph.',
    },
    schema: [],
    messages: {
      destructive: 'Render this error message through <InputError :message="…" /> from @/shared/ui/InputError.vue.',
    },
  },
  create(context) {
    const parserServices = context.sourceCode.parserServices;

    if (parserServices?.defineTemplateBodyVisitor === undefined) {
      return {};
    }

    return parserServices.defineTemplateBodyVisitor({
      VElement(element) {
        if (element.rawName === 'p' && hasClassToken(element, 'text-destructive')) {
          context.report({ node: element.startTag, messageId: 'destructive' });
        }
      },
    });
  },
};

export default noDestructiveText;
