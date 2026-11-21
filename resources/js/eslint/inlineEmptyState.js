import { directiveExpression, hasClassToken } from './templateAttributes.js';

function trailingOperand(expression) {
  if (expression.type === 'LogicalExpression') {
    return trailingOperand(expression.right);
  }

  return expression;
}

function endsWithEmptyLengthCheck(expression) {
  const operand = trailingOperand(expression);

  return (
    operand.type === 'BinaryExpression' &&
    operand.operator === '===' &&
    operand.right.type === 'Literal' &&
    operand.right.value === 0 &&
    operand.left.type === 'MemberExpression' &&
    !operand.left.computed &&
    operand.left.property.type === 'Identifier' &&
    operand.left.property.name === 'length'
  );
}

/** @type {import('eslint').Rule.RuleModule} */
const inlineEmptyState = {
  meta: {
    type: 'problem',
    docs: {
      description: 'An empty-list sentence is rendered through <EmptyState variant="inline">.',
    },
    schema: [],
    messages: {
      inline:
        'Render this empty-list sentence through <EmptyState variant="inline" :description="…" /> from @/shared/ui/EmptyState.vue.',
    },
  },
  create(context) {
    const parserServices = context.sourceCode.parserServices;

    if (parserServices?.defineTemplateBodyVisitor === undefined) {
      return {};
    }

    return parserServices.defineTemplateBodyVisitor({
      VElement(element) {
        if (element.rawName !== 'p' || !hasClassToken(element, 'text-muted-foreground')) {
          return;
        }

        const condition = directiveExpression(element, 'if') ?? directiveExpression(element, 'else-if');

        if (condition === null || !endsWithEmptyLengthCheck(condition)) {
          return;
        }

        context.report({ node: element.startTag, messageId: 'inline' });
      },
    });
  },
};

export default inlineEmptyState;
