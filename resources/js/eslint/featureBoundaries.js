import path from 'node:path';

const legacyRoots = ['components', 'composables', 'lib', 'types', 'layouts', 'pages', 'features'];

const moduleTypes = [
  { prefix: 'feature-', type: 'feature' },
  { prefix: 'ui-', type: 'ui' },
  { prefix: 'util-', type: 'util' },
];

const allowedTargets = {
  feature: ['ui', 'data', 'util'],
  ui: ['data', 'util'],
  data: ['util'],
  util: [],
};

function repoPath(filename) {
  const normalized = filename.split(path.sep).join('/');
  const match = normalized.match(/(?:^|\/)((?:resources\/js|(?:vendor|packages)\/nubos\/[^/]+)\/.*)$/);

  if (!match) {
    return null;
  }

  return match[1].replace(/^vendor\/nubos\//, 'packages/nubos/');
}

function moduleType(name) {
  if (name === 'data') {
    return 'data';
  }

  return moduleTypes.find((candidate) => name.startsWith(candidate.prefix))?.type ?? null;
}

function originOf(file) {
  const module = file.match(/^packages\/nubos\/([^/]+)\//);

  return module ? module[1] : 'core';
}

function sourceRoot(origin) {
  return origin === 'core' ? 'resources/js' : `packages/nubos/${origin}/resources/js`;
}

function locate(file) {
  const origin = originOf(file);

  if (origin !== 'core' && new RegExp(`^packages/nubos/${origin}/tests/`).test(file)) {
    return { origin, kind: 'tests' };
  }

  const base = sourceRoot(origin);

  if (!file.startsWith(`${base}/`) && file !== base) {
    return { origin, kind: null };
  }

  const relative = file.slice(base.length + 1);
  const domainModule = relative.match(/^domains\/([^/]+)\/([^/]+)(\/|$)/);

  if (domainModule) {
    return {
      origin,
      kind: 'module',
      domain: domainModule[1],
      module: domainModule[2],
      type: moduleType(domainModule[2]),
      root: `${base}/domains/${domainModule[1]}/${domainModule[2]}`,
    };
  }

  const platformModule = origin === 'core' ? relative.match(/^platform\/([^/]+)(\/|$)/) : null;

  if (platformModule) {
    return {
      origin,
      kind: 'module',
      domain: 'platform',
      module: platformModule[1],
      type: moduleType(platformModule[1]),
      root: `${base}/platform/${platformModule[1]}`,
    };
  }

  const extension = origin !== 'core' ? relative.match(/^extensions\/([^/]+)(\/|$)/) : null;

  if (extension) {
    return { origin, kind: 'extension', module: extension[1], root: `${base}/extensions/${extension[1]}` };
  }

  if (origin === 'core' && relative === 'app.ts') {
    return { origin, kind: 'app-shell' };
  }

  if (origin === 'core' && /^app-shell(\/|$)/.test(relative)) {
    return { origin, kind: 'app-shell', root: `${base}/app-shell` };
  }

  const area = relative.match(/^(shared|tests)(\/|$)/);

  return { origin, kind: area ? area[1] : null };
}

function isSpec(file) {
  return file.endsWith('.spec.ts') || /\/tests\//.test(file) || file.includes('/shared/testing/');
}

function target(file, specifier) {
  const bare = specifier.split('?')[0];

  if (bare.startsWith('@/')) {
    return path.posix.normalize(`resources/js/${bare.slice(2)}`);
  }

  if (bare.startsWith('.')) {
    return path.posix
      .normalize(path.posix.join(path.posix.dirname(file), bare))
      .replace(/^vendor\/nubos\//, 'packages/nubos/');
  }

  return null;
}

function legacyProblem(resolved) {
  const legacy = resolved.match(/^(?:resources\/js|packages\/nubos\/[^/]+\/resources\/js)\/([^/]+)(\/|$)/);

  if (!legacy || !legacyRoots.includes(legacy[1])) {
    return null;
  }

  if (legacy[1] !== 'features' && !resolved.startsWith('resources/js/')) {
    return null;
  }

  return { messageId: 'legacyRoot', data: { root: legacy[1] } };
}

function throughRoot(specifier, resolved, imported, importer) {
  if (resolved !== imported.root) {
    return false;
  }

  if (imported.origin === 'core') {
    return specifier.startsWith('@/');
  }

  return imported.origin === importer.origin && specifier.startsWith('.');
}

function verdict(file, specifier) {
  const resolved = target(file, specifier);

  if (resolved === null) {
    return null;
  }

  const legacy = legacyProblem(resolved);

  if (legacy) {
    return legacy;
  }

  const importer = locate(file);
  const imported = locate(resolved);

  if (imported.kind === 'tests' && imported.origin === 'core') {
    return importer.kind === 'tests' && importer.origin === 'core' ? null : { messageId: 'testsImport' };
  }

  if (importer.kind === 'module' && importer.type === null) {
    return { messageId: 'unknownModuleType', data: { module: importer.module } };
  }

  const isModuleLike = ['module', 'extension', 'app-shell'].includes(imported.kind);

  if (!isModuleLike) {
    return null;
  }

  if (isSpec(file)) {
    return null;
  }

  if (importer.kind === 'shared' && importer.origin === imported.origin) {
    return { messageId: 'sharedImportsDomain' };
  }

  if (importer.origin !== imported.origin && imported.origin !== 'core') {
    return null;
  }

  const sameModule = importer.root !== undefined && importer.root === imported.root;

  if (sameModule) {
    return resolved === imported.root ? { messageId: 'ownBarrel' } : null;
  }

  if (imported.kind === 'extension') {
    return { messageId: 'extensionImport' };
  }

  if (imported.kind === 'app-shell') {
    if (importer.origin === 'core' && importer.kind !== 'app-shell') {
      return { messageId: 'appShellImport' };
    }

    return throughRoot(specifier, resolved, imported, importer) ? null : { messageId: 'deepImport' };
  }

  if (!throughRoot(specifier, resolved, imported, importer)) {
    return { messageId: 'deepImport' };
  }

  if (importer.kind === 'app-shell') {
    return null;
  }

  if (imported.type === 'feature') {
    return { messageId: 'typeViolation', data: { from: importer.type ?? importer.kind, to: 'feature' } };
  }

  if (importer.kind !== 'module' || importer.origin !== imported.origin) {
    return null;
  }

  if (imported.type !== null && !allowedTargets[importer.type].includes(imported.type)) {
    return { messageId: 'typeViolation', data: { from: importer.type, to: imported.type } };
  }

  if (importer.origin === imported.origin && importer.domain !== imported.domain) {
    return { messageId: 'domainViolation', data: { from: importer.domain, to: imported.domain } };
  }

  return null;
}

/** @type {import('eslint').Rule.RuleModule} */
const featureBoundaries = {
  meta: {
    type: 'problem',
    docs: {
      description:
        'Domains and their feature/ui/data/util modules follow the Sheriff dependency rules; shared never imports a domain.',
    },
    schema: [],
    messages: {
      typeViolation:
        'A {{from}} module may not import a {{to}} module (feature → ui/data/util, ui → data/util, data → util, util → nothing but shared).',
      domainViolation:
        'The domain "{{from}}" may not import the domain "{{to}}"; a domain uses only its own modules and shared.',
      deepImport:
        'Import another module only through its index.ts (@/domains/<domain>/<module>, @/platform/<module>, @/app-shell, or the relative module folder inside a package).',
      ownBarrel: 'Import your own module relatively, never through its index.ts.',
      sharedImportsDomain: 'shared/ sits below every domain and never imports one, not even a type.',
      appShellImport: 'Core domains never import the app shell; the app shell composes them.',
      extensionImport: 'Extensions plug into a host and are never imported by another module.',
      unknownModuleType:
        'The module "{{module}}" needs a category: feature-*, ui-*, util-* or the one data module of its domain.',
      legacyRoot: 'resources/js/{{root}} no longer exists; import from domains/, app-shell/, platform/ or shared/.',
      testsImport: 'resources/js/tests holds the core guards only; import shared test helpers from @/shared/testing.',
    },
  },
  create(context) {
    const file = repoPath(context.filename);

    if (file === null) {
      return {};
    }

    function check(node, source) {
      if (!source || typeof source.value !== 'string') {
        return;
      }

      const problem = verdict(file, source.value);

      if (problem) {
        context.report({ node: source, ...problem });
      }
    }

    return {
      ImportDeclaration: (node) => check(node, node.source),
      ExportNamedDeclaration: (node) => check(node, node.source),
      ExportAllDeclaration: (node) => check(node, node.source),
      ImportExpression: (node) => check(node, node.source),
      CallExpression(node) {
        const callee = node.callee;

        if (
          callee.type === 'MemberExpression' &&
          callee.object.type === 'Identifier' &&
          callee.object.name === 'vi' &&
          node.arguments[0]?.type === 'Literal'
        ) {
          check(node, node.arguments[0]);
        }
      },
    };
  },
};

export default featureBoundaries;
