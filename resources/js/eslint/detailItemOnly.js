/** @type {import('eslint').Rule.RuleModule} */
const detailItemOnly = {
  meta: {
    type: 'problem',
    docs: {
      description: 'A label/value row is rendered through <DetailItem> inside <DetailList>, never a hand-built <dt>.',
    },
    schema: [],
    messages: {
      detailItem:
        'Render this row through <DetailItem :label="…"> (variant="summary" for a label-left/value-right row) inside <DetailList> from @/shared/ui instead of a raw <dt>.',
    },
  },
  create(context) {
    const parserServices = context.sourceCode.parserServices;

    if (parserServices?.defineTemplateBodyVisitor === undefined) {
      return {};
    }

    return parserServices.defineTemplateBodyVisitor({
      VElement(element) {
        if (element.rawName === 'dt') {
          context.report({ node: element.startTag, messageId: 'detailItem' });
        }
      },
    });
  },
};

export default detailItemOnly;
