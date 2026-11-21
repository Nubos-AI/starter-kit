import { staticClass } from './templateAttributes.js';

const frameClassPattern = /(^|\s)(grid|flex-col|space-y-\S+)(\s|$)/;

function firstChildElement(element) {
  return element.children.find((child) => child.type === 'VElement') ?? null;
}

/** @type {import('eslint').Rule.RuleModule} */
const formFieldFrame = {
  meta: {
    type: 'problem',
    docs: {
      description: 'A form field is rendered through FormField, never through a hand-written frame around a Label.',
    },
    schema: [],
    messages: {
      frame:
        'Render this field through <FormField> from @/shared/ui/FormField.vue instead of a hand-written frame around <Label>.',
      errorInSlot:
        'Pass the message through the :error prop of <FormField> instead of an <InputError> inside its slot.',
    },
  },
  create(context) {
    const parserServices = context.sourceCode.parserServices;

    if (parserServices?.defineTemplateBodyVisitor === undefined) {
      return {};
    }

    return parserServices.defineTemplateBodyVisitor({
      VElement(element) {
        const parent = element.parent;

        if (element.rawName === 'InputError' && parent?.type === 'VElement' && parent.rawName === 'FormField') {
          context.report({ node: element.startTag, messageId: 'errorInSlot' });

          return;
        }

        if (element.rawName !== 'div') {
          return;
        }

        const className = staticClass(element);

        if (className === null || !frameClassPattern.test(className)) {
          return;
        }

        if (firstChildElement(element)?.rawName !== 'Label') {
          return;
        }

        context.report({ node: element.startTag, messageId: 'frame' });
      },
    });
  },
};

export default formFieldFrame;
