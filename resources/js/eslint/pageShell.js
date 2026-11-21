import { staticClass } from './templateAttributes.js';

const shellClass = 'flex flex-col gap-6 p-3';

function childElements(element) {
  return element.children.filter((child) => child.type === 'VElement');
}

function containsHeading(element) {
  return childElements(element).some((child) => child.rawName === 'Heading' || containsHeading(child));
}

function isTemplateRoot(element) {
  const parent = element.parent;

  return parent?.type === 'VElement' && parent.name === 'template' && parent.parent?.type === 'VDocumentFragment';
}

/** @type {import('eslint').Rule.RuleModule} */
const pageShell = {
  meta: {
    type: 'problem',
    docs: {
      description: 'A page renders its shell, title and heading through PageShell, never through a hand-built root.',
    },
    schema: [],
    messages: {
      shell:
        'Render this page through <PageShell :title :description> from @/shared/ui/PageShell.vue instead of a hand-built root with <Head> and <Heading>.',
    },
  },
  create(context) {
    const parserServices = context.sourceCode.parserServices;

    if (parserServices?.defineTemplateBodyVisitor === undefined) {
      return {};
    }

    return parserServices.defineTemplateBodyVisitor({
      VElement(element) {
        if (element.rawName !== 'div' || !isTemplateRoot(element)) {
          return;
        }

        if (!(staticClass(element) ?? '').includes(shellClass)) {
          return;
        }

        if (!containsHeading(element)) {
          return;
        }

        context.report({ node: element.startTag, messageId: 'shell' });
      },
    });
  },
};

export default pageShell;
