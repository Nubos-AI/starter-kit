function isNavigatorClipboard(node) {
  return (
    node.type === 'MemberExpression' &&
    node.object.type === 'Identifier' &&
    node.object.name === 'navigator' &&
    !node.computed &&
    node.property.type === 'Identifier' &&
    node.property.name === 'clipboard'
  );
}

/** @type {import('eslint').Rule.RuleModule} */
const clipboardComposable = {
  meta: {
    type: 'problem',
    docs: {
      description: 'Copying to the clipboard goes through useClipboard from @/shared/composables/useClipboard.',
    },
    schema: [],
    messages: {
      navigator:
        'Copy through useClipboard() from @/shared/composables/useClipboard (or <CopyField>) instead of navigator.clipboard.',
      vueuse:
        'Import useClipboard from @/shared/composables/useClipboard; the @vueuse/core variant reports no failure and shows no toast.',
    },
  },
  create(context) {
    const visitor = {
      MemberExpression(node) {
        if (isNavigatorClipboard(node)) {
          context.report({ node, messageId: 'navigator' });
        }
      },
      ImportDeclaration(node) {
        if (node.source.value !== '@vueuse/core') {
          return;
        }

        node.specifiers
          .filter(
            (specifier) =>
              specifier.type === 'ImportSpecifier' &&
              specifier.imported.type === 'Identifier' &&
              specifier.imported.name === 'useClipboard',
          )
          .forEach((specifier) => context.report({ node: specifier, messageId: 'vueuse' }));
      },
    };

    const parserServices = context.sourceCode.parserServices;

    if (parserServices?.defineTemplateBodyVisitor === undefined) {
      return visitor;
    }

    return parserServices.defineTemplateBodyVisitor({ MemberExpression: visitor.MemberExpression }, visitor);
  },
};

export default clipboardComposable;
