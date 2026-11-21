export function staticAttribute(element, name) {
  const attribute = element.startTag.attributes.find(
    (candidate) => !candidate.directive && candidate.key.name === name,
  );

  return attribute?.value?.value ?? null;
}

export function staticClass(element) {
  return staticAttribute(element, 'class');
}

export function hasClassToken(element, token) {
  const className = staticClass(element);

  return className !== null && className.split(/\s+/).includes(token);
}

export function directiveExpression(element, name) {
  const directive = element.startTag.attributes.find(
    (candidate) => candidate.directive && candidate.key.name.name === name,
  );

  return directive?.value?.expression ?? null;
}

export function boundExpression(element, name) {
  const binding = element.startTag.attributes.find(
    (candidate) =>
      candidate.directive &&
      candidate.key.name.name === 'bind' &&
      candidate.key.argument?.type === 'VIdentifier' &&
      candidate.key.argument.name === name,
  );

  return binding?.value?.expression ?? null;
}

export function hasDirective(element, name) {
  return element.startTag.attributes.some((candidate) => candidate.directive && candidate.key.name.name === name);
}
