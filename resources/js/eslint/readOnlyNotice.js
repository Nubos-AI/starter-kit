import { directiveExpression } from './templateAttributes.js';

function lastName(node) {
  if (node.type === 'Identifier') {
    return node.name;
  }

  if (node.type === 'MemberExpression' && !node.computed && node.property.type === 'Identifier') {
    return node.property.name;
  }

  return null;
}

function negatesCapability(expression) {
  if (expression.type === 'LogicalExpression' && expression.operator === '&&') {
    return negatesCapability(expression.left);
  }

  if (expression.type !== 'UnaryExpression' || expression.operator !== '!') {
    return false;
  }

  return /^can(_|[A-Z])/.test(lastName(expression.argument) ?? '');
}

/** @type {import('eslint').Rule.RuleModule} */
const readOnlyNotice = {
  meta: {
    type: 'problem',
    docs: {
      description: 'A notice shown because the user lacks a capability is rendered through <ReadOnlyNotice>.',
    },
    schema: [],
    messages: {
      readOnly:
        'Render this read-only notice through <ReadOnlyNotice :reason="…" /> from @/shared/ui/ReadOnlyNotice.vue instead of a hand-built <Alert>.',
    },
  },
  create(context) {
    const parserServices = context.sourceCode.parserServices;

    if (parserServices?.defineTemplateBodyVisitor === undefined) {
      return {};
    }

    return parserServices.defineTemplateBodyVisitor({
      VElement(element) {
        if (element.rawName !== 'Alert') {
          return;
        }

        const condition = directiveExpression(element, 'if') ?? directiveExpression(element, 'else-if');

        if (condition === null || !negatesCapability(condition)) {
          return;
        }

        context.report({ node: element.startTag, messageId: 'readOnly' });
      },
    });
  },
};

export default readOnlyNotice;
