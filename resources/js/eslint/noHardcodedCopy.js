const copyProperties = new Set([
  'label',
  'title',
  'description',
  'placeholder',
  'message',
  'text',
  'emptyText',
  'emptyTitle',
  'emptyDescription',
  'tooltip',
  'reason',
  'headerName',
  'heading',
  'hint',
  'helpText',
  'confirmLabel',
  'cancelLabel',
]);

const copyAttributes = new Set([
  'label',
  'title',
  'description',
  'placeholder',
  'aria-label',
  'alt',
  'tooltip',
  'empty-text',
  'empty-title',
  'empty-description',
  'confirm-label',
  'cancel-label',
]);

const toastMethods = new Set(['success', 'error', 'info', 'warning', 'message']);

const germanLetters = /[äöüÄÖÜß]/;

const germanWords =
  /\b(Sie|Ihre?n?|nicht|bitte|Bitte|wurde|werden|keine?n?|Kein|Keine|abgebrochen|abgeschlossen|gespeichert|gelöscht|ist|und|oder|der|die|das)\b/;

const translationKey = /^([\w-]+::)?i18n(\.[\w-]+)+$/;

function textOf(node) {
  if (node.type === 'Literal' && typeof node.value === 'string') {
    return node.value;
  }

  if (node.type === 'TemplateLiteral') {
    return node.quasis.map((quasi) => quasi.value.cooked ?? '').join(' ');
  }

  return null;
}

function isGerman(text) {
  return germanLetters.test(text) || (/\s/.test(text.trim()) && germanWords.test(text));
}

function isProse(text) {
  const trimmed = text.trim();

  if (trimmed === '' || translationKey.test(trimmed) || !/[A-Za-zÄÖÜäöüß]{2,}/.test(trimmed)) {
    return false;
  }

  return /^[A-ZÄÖÜ]/.test(trimmed) || /[A-Za-zÄÖÜäöüß]+\s+[A-Za-zÄÖÜäöüß]+/.test(trimmed);
}

function propertyName(node) {
  const parent = node.parent;

  if (parent?.type !== 'Property' || parent.value !== node) {
    return null;
  }

  if (parent.key.type === 'Identifier') {
    return parent.key.name;
  }

  return typeof parent.key.value === 'string' ? parent.key.value : null;
}

function isToastArgument(node) {
  const call = node.parent;

  if (call?.type !== 'CallExpression' || call.arguments[0] !== node) {
    return false;
  }

  const callee = call.callee;

  if (callee.type === 'Identifier') {
    return callee.name === 'toast' || callee.name === 'alert' || callee.name === 'confirm';
  }

  return (
    callee.type === 'MemberExpression' &&
    callee.object.type === 'Identifier' &&
    callee.object.name === 'toast' &&
    callee.property.type === 'Identifier' &&
    toastMethods.has(callee.property.name)
  );
}

function isImportSource(node) {
  const parent = node.parent;

  return (
    (parent?.type === 'ImportDeclaration' ||
      parent?.type === 'ExportNamedDeclaration' ||
      parent?.type === 'ExportAllDeclaration' ||
      parent?.type === 'ImportExpression') &&
    parent.source === node
  );
}

function boundAttributeName(node) {
  let current = node.parent;

  while (current && current.type !== 'VExpressionContainer') {
    current = current.parent;
  }

  const attribute = current?.parent;

  if (attribute?.type !== 'VAttribute' || !attribute.directive || attribute.key.name?.name !== 'bind') {
    return null;
  }

  const argument = attribute.key.argument;

  return argument?.type === 'VIdentifier' ? argument.rawName : null;
}

function reasonFor(node, text, inTemplate) {
  if (translationKey.test(text.trim())) {
    return null;
  }

  if (isGerman(text)) {
    return 'german';
  }

  if (!isProse(text)) {
    return null;
  }

  if (isToastArgument(node)) {
    return 'toast';
  }

  if (inTemplate) {
    const attribute = boundAttributeName(node);

    return attribute !== null && copyAttributes.has(attribute) ? 'attribute' : null;
  }

  const property = propertyName(node);

  return property !== null && copyProperties.has(property) ? 'property' : null;
}

/** @type {import('eslint').Rule.RuleModule} */
const noHardcodedCopy = {
  meta: {
    type: 'problem',
    docs: {
      description: 'User-visible copy must come from the translation catalogue through t().',
    },
    schema: [],
    messages: {
      german: 'German copy belongs in i18n.json and is rendered through t(): "{{text}}".',
      toast: 'Toast and dialog copy belongs in i18n.json and is rendered through t(): "{{text}}".',
      property: 'The copy of "{{name}}" belongs in i18n.json and is rendered through t(): "{{text}}".',
      attribute: 'The copy of the bound attribute belongs in i18n.json and is rendered through t(): "{{text}}".',
    },
  },
  create(context) {
    function check(node, inTemplate) {
      const text = textOf(node);

      if (text === null || isImportSource(node)) {
        return;
      }

      const reason = reasonFor(node, text, inTemplate);

      if (reason === null) {
        return;
      }

      context.report({
        node,
        messageId: reason,
        data: {
          text: text.trim().slice(0, 60),
          name: propertyName(node) ?? '',
        },
      });
    }

    const scriptVisitor = {
      Literal: (node) => check(node, false),
      TemplateLiteral: (node) => check(node, false),
    };

    const parserServices = context.sourceCode.parserServices;

    if (parserServices?.defineTemplateBodyVisitor === undefined) {
      return scriptVisitor;
    }

    return parserServices.defineTemplateBodyVisitor(
      {
        Literal: (node) => check(node, true),
        TemplateLiteral: (node) => check(node, true),
      },
      scriptVisitor,
    );
  },
};

export default noHardcodedCopy;
