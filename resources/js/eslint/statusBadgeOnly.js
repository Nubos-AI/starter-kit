import { boundExpression } from './templateAttributes.js';

function calleeName(callee) {
  if (callee.type === 'Identifier') {
    return callee.name;
  }

  if (callee.type === 'MemberExpression' && !callee.computed && callee.property.type === 'Identifier') {
    return callee.property.name;
  }

  return null;
}

function objectName(node) {
  if (node.type === 'Identifier') {
    return node.name;
  }

  if (node.type === 'MemberExpression' && !node.computed && node.property.type === 'Identifier') {
    return node.property.name;
  }

  return null;
}

function resolvesStatusVariant(expression) {
  if (expression.type === 'ChainExpression') {
    return resolvesStatusVariant(expression.expression);
  }

  if (expression.type === 'LogicalExpression') {
    return resolvesStatusVariant(expression.left);
  }

  if (expression.type === 'CallExpression') {
    return /Variant$/.test(calleeName(expression.callee) ?? '');
  }

  if (expression.type !== 'MemberExpression') {
    return false;
  }

  if (!expression.computed) {
    return expression.property.type === 'Identifier' && expression.property.name === 'variant';
  }

  return /Variants$/.test(objectName(expression.object) ?? '');
}

/** @type {import('eslint').Rule.RuleModule} */
const statusBadgeOnly = {
  meta: {
    type: 'problem',
    docs: {
      description: 'A status value is rendered through <StatusBadge> and a StatusMap, never a hand-resolved <Badge>.',
    },
    schema: [],
    messages: {
      statusBadge:
        'Render this status through <StatusBadge :map="…" :status="…" /> from @/shared/ui/primitives/status-badge (pass :label for a server-provided label) instead of resolving the Badge variant by hand.',
    },
  },
  create(context) {
    const parserServices = context.sourceCode.parserServices;

    if (parserServices?.defineTemplateBodyVisitor === undefined) {
      return {};
    }

    return parserServices.defineTemplateBodyVisitor({
      VElement(element) {
        if (element.rawName !== 'Badge') {
          return;
        }

        const variant = boundExpression(element, 'variant');

        if (variant === null || !resolvesStatusVariant(variant)) {
          return;
        }

        context.report({ node: element.startTag, messageId: 'statusBadge' });
      },
    });
  },
};

export default statusBadgeOnly;
