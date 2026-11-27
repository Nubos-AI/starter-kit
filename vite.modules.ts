import { existsSync, readdirSync } from 'node:fs';
import { join, sep } from 'node:path';
import type { Alias } from 'vite';

function directories(path: string): string[] {
  return existsSync(path)
    ? readdirSync(path, { withFileTypes: true })
        .filter((entry) => entry.isDirectory() || entry.isSymbolicLink())
        .map((entry) => entry.name)
    : [];
}

export function installedModules(root: string): string[] {
  const vendor = join(root, 'vendor');

  return directories(vendor)
    .flatMap((owner) => directories(join(vendor, owner)).map((name) => `${owner}/${name}`))
    .filter((module) => existsSync(join(vendor, module, 'module.json')));
}

export function moduleAliases(root: string): Alias[] {
  return [{ find: /^@modules\/([^/]+\/[^/]+)$/, replacement: `${join(root, 'vendor')}/$1/resources/js/index.ts` }];
}

export function ignoresVendorPath(root: string, modules: string[]): (path: string) => boolean {
  const vendor = join(root, 'vendor').split(sep).join('/');

  return (path) => {
    const normalized = path.split(sep).join('/');

    if (!normalized.startsWith(`${vendor}/`)) {
      return normalized.includes('/vendor/');
    }

    const [owner, name] = normalized.slice(vendor.length + 1).split('/');

    return name === undefined
      ? !modules.some((module) => module.startsWith(`${owner}/`))
      : !modules.includes(`${owner}/${name}`);
  };
}
