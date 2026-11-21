import { boundExpression, hasClassToken, hasDirective } from './templateAttributes.js';

function mentionsPulse(expression) {
  if (expression === null) {
    return false;
  }

  if (expression.type === 'Literal') {
    return typeof expression.value === 'string' && expression.value.split(/\s+/).includes('animate-pulse');
  }

  if (expression.type === 'TemplateLiteral') {
    return expression.quasis.some((quasi) => quasi.value.raw.split(/\s+/).includes('animate-pulse'));
  }

  if (expression.type === 'ConditionalExpression') {
    return mentionsPulse(expression.consequent) || mentionsPulse(expression.alternate);
  }

  if (expression.type === 'ArrayExpression') {
    return expression.elements.some((entry) => entry !== null && mentionsPulse(entry));
  }

  return false;
}

/** @type {import('eslint').Rule.RuleModule} */
const skeletonList = {
  meta: {
    type: 'problem',
    docs: {
      description: 'Repeated loading placeholders come from <SkeletonList>; a <Skeleton> never carries a second pulse.',
    },
    schema: [],
    messages: {
      list: 'Render repeated placeholders through <SkeletonList :count="…" size="sm|md|lg" /> from @/shared/ui/SkeletonList.vue instead of <Skeleton v-for>.',
      pulse: '<Skeleton> already pulses; drop the redundant animate-pulse class.',
    },
  },
  create(context) {
    const parserServices = context.sourceCode.parserServices;

    if (parserServices?.defineTemplateBodyVisitor === undefined) {
      return {};
    }

    return parserServices.defineTemplateBodyVisitor({
      VElement(element) {
        if (element.rawName !== 'Skeleton') {
          return;
        }

        if (hasDirective(element, 'for')) {
          context.report({ node: element.startTag, messageId: 'list' });
        }

        if (hasClassToken(element, 'animate-pulse') || mentionsPulse(boundExpression(element, 'class'))) {
          context.report({ node: element.startTag, messageId: 'pulse' });
        }
      },
    });
  },
};

export default skeletonList;
