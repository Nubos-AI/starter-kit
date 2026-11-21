import { hasClassToken } from './templateAttributes.js';

/** @type {import('eslint').Rule.RuleModule} */
const cardSectionHeading = {
  meta: {
    type: 'problem',
    docs: {
      description: 'A titled block inside a card is rendered through <CardSection>, never a hand-built <h3>.',
    },
    schema: [],
    messages: {
      cardSection:
        'Render this block through <CardSection :title="…" :description="…"> from @/shared/ui/CardSection.vue (or <SubsectionHeading> for a small sub-heading) instead of a raw <h3 class="text-lg font-bold">.',
    },
  },
  create(context) {
    const parserServices = context.sourceCode.parserServices;

    if (parserServices?.defineTemplateBodyVisitor === undefined) {
      return {};
    }

    return parserServices.defineTemplateBodyVisitor({
      VElement(element) {
        if (element.rawName !== 'h3' || !hasClassToken(element, 'text-lg') || !hasClassToken(element, 'font-bold')) {
          return;
        }

        context.report({ node: element.startTag, messageId: 'cardSection' });
      },
    });
  },
};

export default cardSectionHeading;
