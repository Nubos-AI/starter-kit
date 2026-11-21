const intlFormatters = new Set(['NumberFormat', 'DateTimeFormat']);

const toLocaleMethods = new Set(['toLocaleString', 'toLocaleDateString', 'toLocaleTimeString']);

function memberName(node) {
  if (node.type !== 'MemberExpression') {
    return null;
  }

  if (!node.computed && node.property.type === 'Identifier') {
    return node.property.name;
  }

  return typeof node.property.value === 'string' ? node.property.value : null;
}

function isIntlFormatter(callee) {
  return (
    callee.type === 'MemberExpression' &&
    callee.object.type === 'Identifier' &&
    callee.object.name === 'Intl' &&
    intlFormatters.has(memberName(callee))
  );
}

/** @type {import('eslint').Rule.RuleModule} */
const noDirectIntlFormat = {
  meta: {
    type: 'problem',
    docs: {
      description:
        'Numbers and dates are formatted through n()/d() from useI18n or the functions in resources/js/shared/lib/.',
    },
    schema: [],
    messages: {
      intl: 'Format through n()/d() from useI18n or the resources/js/shared/lib/ functions instead of Intl.{{name}}.',
      toLocale: 'Format through n()/d() from useI18n or the resources/js/shared/lib/ functions instead of {{name}}().',
    },
  },
  create(context) {
    function checkConstruction(node) {
      if (!isIntlFormatter(node.callee)) {
        return;
      }

      context.report({
        node,
        messageId: 'intl',
        data: { name: memberName(node.callee) },
      });
    }

    function checkCall(node) {
      if (isIntlFormatter(node.callee)) {
        checkConstruction(node);

        return;
      }

      const name = memberName(node.callee);

      if (name === null || !toLocaleMethods.has(name)) {
        return;
      }

      context.report({ node, messageId: 'toLocale', data: { name } });
    }

    const visitor = {
      NewExpression: checkConstruction,
      CallExpression: checkCall,
    };

    const parserServices = context.sourceCode.parserServices;

    if (parserServices?.defineTemplateBodyVisitor === undefined) {
      return visitor;
    }

    return parserServices.defineTemplateBodyVisitor(visitor, visitor);
  },
};

export default noDirectIntlFormat;
