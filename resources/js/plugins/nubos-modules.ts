import fs from 'node:fs';
import path from 'node:path';
import type { Plugin } from 'vite';

export interface NubosModulesOptions {
    /**
     * Path to the vendor directory containing nubos packages.
     * Defaults to `vendor/nubos` relative to the Vite root.
     */
    vendorPath?: string;

    /**
     * Subdirectories within each package's `resources/js/` to register as aliases.
     * Defaults to `['composables', 'types']`.
     *
     * Each entry creates an alias: `@nubos/<package-name>/<subdirectory>`
     */
    subdirectories?: string[];
}

export interface DiscoveredAlias {
    alias: string;
    path: string;
}

/**
 * Scans `vendor/nubos/` for packages with `resources/js/` directories
 * and returns the discovered aliases.
 */
export function discoverAliases(
    root: string,
    options: NubosModulesOptions = {},
): DiscoveredAlias[] {
    const vendorPath = path.resolve(root, options.vendorPath ?? 'vendor/nubos');
    const subdirectories = options.subdirectories ?? ['composables', 'types'];

    if (!fs.existsSync(vendorPath)) {
        return [];
    }

    const aliases: DiscoveredAlias[] = [];

    let entries: fs.Dirent[];
    try {
        entries = fs.readdirSync(vendorPath, { withFileTypes: true });
    } catch {
        return [];
    }

    for (const entry of entries) {
        if (!entry.isDirectory()) continue;

        const packageName = entry.name;
        const jsDir = path.join(vendorPath, packageName, 'resources', 'js');

        if (!fs.existsSync(jsDir)) continue;

        for (const subdir of subdirectories) {
            const subdirPath = path.join(jsDir, subdir);

            if (fs.existsSync(subdirPath)) {
                aliases.push({
                    alias: `@nubos/${packageName}/${subdir}`,
                    path: subdirPath,
                });
            }
        }
    }

    return aliases;
}

/**
 * Vite plugin that automatically registers aliases for Nubos vendor packages.
 *
 * Scans `vendor/nubos/<package>/resources/js/` for subdirectories
 * and registers them as `@nubos/<package>/<subdirectory>` aliases.
 */
export function nubosModules(options: NubosModulesOptions = {}): Plugin {
    return {
        name: 'nubos-modules',

        config(config) {
            const root = config.root ?? process.cwd();
            const aliases = discoverAliases(root, options);

            if (aliases.length === 0) {
                return;
            }

            const aliasEntries = aliases.map(({ alias, path: aliasPath }) => ({
                find: alias,
                replacement: aliasPath,
            }));

            return {
                resolve: {
                    alias: aliasEntries,
                },
            };
        },
    };
}

export default nubosModules;
