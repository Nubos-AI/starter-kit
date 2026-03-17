import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { discoverAliases, nubosModules } from '../../resources/js/plugins/nubos-modules';

function createTempDir(): string {
    return fs.mkdtempSync(path.join(os.tmpdir(), 'nubos-vite-plugin-'));
}

function createPackageStructure(
    root: string,
    packageName: string,
    subdirs: string[] = ['composables', 'types'],
): void {
    for (const subdir of subdirs) {
        fs.mkdirSync(
            path.join(root, 'vendor', 'nubos', packageName, 'resources', 'js', subdir),
            { recursive: true },
        );
    }
}

describe('discoverAliases', () => {
    let tempDir: string;

    beforeEach(() => {
        tempDir = createTempDir();
    });

    afterEach(() => {
        fs.rmSync(tempDir, { recursive: true, force: true });
    });

    it('returns empty array when vendor/nubos does not exist', () => {
        const aliases = discoverAliases(tempDir);
        expect(aliases).toEqual([]);
    });

    it('returns empty array when vendor/nubos is empty', () => {
        fs.mkdirSync(path.join(tempDir, 'vendor', 'nubos'), { recursive: true });
        const aliases = discoverAliases(tempDir);
        expect(aliases).toEqual([]);
    });

    it('discovers composables and types aliases for a package', () => {
        createPackageStructure(tempDir, 'billing');

        const aliases = discoverAliases(tempDir);

        expect(aliases).toEqual([
            {
                alias: '@nubos/billing/composables',
                path: path.join(tempDir, 'vendor', 'nubos', 'billing', 'resources', 'js', 'composables'),
            },
            {
                alias: '@nubos/billing/types',
                path: path.join(tempDir, 'vendor', 'nubos', 'billing', 'resources', 'js', 'types'),
            },
        ]);
    });

    it('discovers multiple packages', () => {
        createPackageStructure(tempDir, 'billing');
        createPackageStructure(tempDir, 'analytics');

        const aliases = discoverAliases(tempDir);

        const aliasNames = aliases.map((a) => a.alias).sort();
        expect(aliasNames).toEqual([
            '@nubos/analytics/composables',
            '@nubos/analytics/types',
            '@nubos/billing/composables',
            '@nubos/billing/types',
        ]);
    });

    it('skips packages without resources/js directory', () => {
        createPackageStructure(tempDir, 'billing');
        fs.mkdirSync(path.join(tempDir, 'vendor', 'nubos', 'core', 'src'), { recursive: true });

        const aliases = discoverAliases(tempDir);

        expect(aliases).toHaveLength(2);
        expect(aliases.every((a) => a.alias.startsWith('@nubos/billing/'))).toBe(true);
    });

    it('only registers aliases for existing subdirectories', () => {
        createPackageStructure(tempDir, 'billing', ['composables']);

        const aliases = discoverAliases(tempDir);

        expect(aliases).toEqual([
            {
                alias: '@nubos/billing/composables',
                path: path.join(tempDir, 'vendor', 'nubos', 'billing', 'resources', 'js', 'composables'),
            },
        ]);
    });

    it('supports custom vendorPath', () => {
        const customVendor = path.join(tempDir, 'custom', 'packages');
        fs.mkdirSync(path.join(customVendor, 'billing', 'resources', 'js', 'composables'), {
            recursive: true,
        });

        const aliases = discoverAliases(tempDir, { vendorPath: path.join('custom', 'packages') });

        expect(aliases).toEqual([
            {
                alias: '@nubos/billing/composables',
                path: path.join(customVendor, 'billing', 'resources', 'js', 'composables'),
            },
        ]);
    });

    it('supports custom subdirectories', () => {
        fs.mkdirSync(
            path.join(tempDir, 'vendor', 'nubos', 'billing', 'resources', 'js', 'components'),
            { recursive: true },
        );
        fs.mkdirSync(
            path.join(tempDir, 'vendor', 'nubos', 'billing', 'resources', 'js', 'stores'),
            { recursive: true },
        );

        const aliases = discoverAliases(tempDir, {
            subdirectories: ['components', 'stores'],
        });

        expect(aliases).toEqual([
            {
                alias: '@nubos/billing/components',
                path: path.join(tempDir, 'vendor', 'nubos', 'billing', 'resources', 'js', 'components'),
            },
            {
                alias: '@nubos/billing/stores',
                path: path.join(tempDir, 'vendor', 'nubos', 'billing', 'resources', 'js', 'stores'),
            },
        ]);
    });

    it('ignores files in vendor/nubos (only processes directories)', () => {
        fs.mkdirSync(path.join(tempDir, 'vendor', 'nubos'), { recursive: true });
        fs.writeFileSync(path.join(tempDir, 'vendor', 'nubos', 'README.md'), '');
        createPackageStructure(tempDir, 'billing');

        const aliases = discoverAliases(tempDir);

        expect(aliases).toHaveLength(2);
    });
});

describe('nubosModules', () => {
    let tempDir: string;

    beforeEach(() => {
        tempDir = createTempDir();
    });

    afterEach(() => {
        fs.rmSync(tempDir, { recursive: true, force: true });
    });

    it('returns a Vite plugin with correct name', () => {
        const plugin = nubosModules();
        expect(plugin.name).toBe('nubos-modules');
    });

    it('returns undefined config when no packages found', () => {
        const plugin = nubosModules();
        const result = (plugin.config as Function)({ root: tempDir }, { command: 'serve' });
        expect(result).toBeUndefined();
    });

    it('returns alias config when packages are found', () => {
        createPackageStructure(tempDir, 'billing');

        const plugin = nubosModules();
        const result = (plugin.config as Function)({ root: tempDir }, { command: 'serve' });

        expect(result).toEqual({
            resolve: {
                alias: [
                    {
                        find: '@nubos/billing/composables',
                        replacement: path.join(
                            tempDir, 'vendor', 'nubos', 'billing', 'resources', 'js', 'composables',
                        ),
                    },
                    {
                        find: '@nubos/billing/types',
                        replacement: path.join(
                            tempDir, 'vendor', 'nubos', 'billing', 'resources', 'js', 'types',
                        ),
                    },
                ],
            },
        });
    });

    it('passes options through to discoverAliases', () => {
        fs.mkdirSync(
            path.join(tempDir, 'vendor', 'nubos', 'billing', 'resources', 'js', 'stores'),
            { recursive: true },
        );

        const plugin = nubosModules({ subdirectories: ['stores'] });
        const result = (plugin.config as Function)({ root: tempDir }, { command: 'serve' });

        expect(result).toEqual({
            resolve: {
                alias: [
                    {
                        find: '@nubos/billing/stores',
                        replacement: path.join(
                            tempDir, 'vendor', 'nubos', 'billing', 'resources', 'js', 'stores',
                        ),
                    },
                ],
            },
        });
    });
});
