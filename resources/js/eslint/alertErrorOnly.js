import { staticAttribute } from './templateAttributes.js';

/** @type {import('eslint').Rule.RuleModule} */
const alertErrorOnly = {
  meta: {
    type: 'problem',
    docs: {
      description: 'A destructive alert is rendered through <AlertError>, never assembled by hand.',
    },
    schema: [],
    messages: {
      alertError:
        'Render this error alert through <AlertError :message="…" /> (or :errors, or its default slot) from @/shared/ui/AlertError.vue.',
    },
  },
  create(context) {
    const parserServices = context.sourceCode.parserServices;

    if (parserServices?.defineTemplateBodyVisitor === undefined) {
      return {};
    }

    return parserServices.defineTemplateBodyVisitor({
      VElement(element) {
        if (element.rawName === 'Alert' && staticAttribute(element, 'variant') === 'destructive') {
          context.report({ node: element.startTag, messageId: 'alertError' });
        }
      },
    });
  },
};

export default alertErrorOnly;
