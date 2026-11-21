import stylistic from '@stylistic/eslint-plugin';
import {
    defineConfigWithVueTs,
    vueTsConfigs,
} from '@vue/eslint-config-typescript';
import prettier from 'eslint-config-prettier/flat';
import importPlugin from 'eslint-plugin-import';
import vue from 'eslint-plugin-vue';
import alertErrorOnly from './resources/js/eslint/alertErrorOnly.js';
import cardSectionHeading from './resources/js/eslint/cardSectionHeading.js';
import clipboardComposable from './resources/js/eslint/clipboardComposable.js';
import detailItemOnly from './resources/js/eslint/detailItemOnly.js';
import featureBoundaries from './resources/js/eslint/featureBoundaries.js';
import formFieldFrame from './resources/js/eslint/formFieldFrame.js';
import inlineEmptyState from './resources/js/eslint/inlineEmptyState.js';
import noDestructiveText from './resources/js/eslint/noDestructiveText.js';
import noDirectIntlFormat from './resources/js/eslint/noDirectIntlFormat.js';
import noHardcodedCopy from './resources/js/eslint/noHardcodedCopy.js';
import pageShell from './resources/js/eslint/pageShell.js';
import readOnlyNotice from './resources/js/eslint/readOnlyNotice.js';
import skeletonList from './resources/js/eslint/skeletonList.js';
import statusBadgeOnly from './resources/js/eslint/statusBadgeOnly.js';

const nubos = {
    rules: {
        'alert-error-only': alertErrorOnly,
        'card-section-heading': cardSectionHeading,
        'clipboard-composable': clipboardComposable,
        'detail-item-only': detailItemOnly,
        'feature-boundaries': featureBoundaries,
        'form-field-frame': formFieldFrame,
        'inline-empty-state': inlineEmptyState,
        'no-destructive-text': noDestructiveText,
        'no-direct-intl-format': noDirectIntlFormat,
        'no-hardcoded-copy': noHardcodedCopy,
        'page-shell': pageShell,
        'read-only-notice': readOnlyNotice,
        'skeleton-list': skeletonList,
        'status-badge-only': statusBadgeOnly,
    },
};

const controlStatements = [
    'if',
    'return',
    'for',
    'while',
    'do',
    'switch',
    'try',
    'throw',
];
const paddingAroundControl = [
    ...controlStatements.flatMap((stmt) => [
        { blankLine: 'always', prev: '*', next: stmt },
        { blankLine: 'always', prev: stmt, next: '*' },
    ]),
];

export default defineConfigWithVueTs(
    vue.configs['flat/essential'],
    vueTsConfigs.recommended,
    {
        plugins: {
            import: importPlugin,
        },
        settings: {
            'import/resolver': {
                typescript: {
                    alwaysTryTypes: true,
                    project: './tsconfig.json',
                },
                node: true,
            },
        },
        rules: {
            'vue/multi-word-component-names': 'off',
            '@typescript-eslint/no-explicit-any': 'error',
            'no-console': 'error',
            '@typescript-eslint/consistent-type-imports': [
                'error',
                {
                    prefer: 'type-imports',
                    fixStyle: 'separate-type-imports',
                },
            ],
            'import/order': [
                'error',
                {
                    groups: [
                        'builtin',
                        'external',
                        'internal',
                        'parent',
                        'sibling',
                        'index',
                    ],
                    pathGroups: [{ pattern: '@/**', group: 'internal' }],
                    alphabetize: { order: 'asc', caseInsensitive: true },
                },
            ],
            'import/consistent-type-specifier-style': [
                'error',
                'prefer-top-level',
            ],
        },
    },
    {
        plugins: {
            '@stylistic': stylistic,
        },
        rules: {
            '@stylistic/brace-style': [
                'error',
                '1tbs',
                { allowSingleLine: false },
            ],
            '@stylistic/padding-line-between-statements': [
                'error',
                ...paddingAroundControl,
            ],
        },
    },
    {
        files: [
            'resources/js/**/*.{ts,vue}',
            'packages/*/*/resources/js/**/*.{ts,vue}',
        ],
        ignores: [
            '**/*.spec.ts',
            '**/tests/**',
            '**/shared/testing/**',
            'resources/js/eslint/**',
        ],
        plugins: { nubos },
        rules: {
            'nubos/no-hardcoded-copy': 'error',
            'vue/no-bare-strings-in-template': [
                'error',
                {
                    allowlist: [
                        '(',
                        ')',
                        ',',
                        '.',
                        '&',
                        '+',
                        '-',
                        '=',
                        '*',
                        '/',
                        '#',
                        '%',
                        '!',
                        '?',
                        ':',
                        '[',
                        ']',
                        '{',
                        '}',
                        '<',
                        '>',
                        '|',
                        '·',
                        '•',
                        '‐',
                        '–',
                        '—',
                        '−',
                        '×',
                        '→',
                        '„',
                        '“',
                        '"',
                        '0',
                        '1',
                        '2',
                        '3',
                        '4',
                        '5',
                        '6',
                        '7',
                        '8',
                        '9',
                        'POST',
                        'AUTOMATION_HTTP_ALLOWED_HOSTS',
                    ],
                },
            ],
        },
    },
    {
        files: [
            'resources/js/**/*.{ts,vue}',
            'packages/*/*/resources/js/**/*.{ts,vue}',
        ],
        ignores: [
            '**/*.spec.ts',
            '**/tests/**',
            'resources/js/eslint/**',
            'resources/js/shared/lib/**',
        ],
        rules: {
            'nubos/no-direct-intl-format': 'error',
        },
    },
    {
        files: [
            'resources/js/**/*.vue',
            'packages/*/*/resources/js/**/*.vue',
        ],
        ignores: [
            'resources/js/shared/ui/FormField.vue',
            'resources/js/shared/ui/primitives/**',
        ],
        plugins: { nubos },
        rules: {
            'nubos/form-field-frame': 'error',
        },
    },
    {
        files: [
            'resources/js/**/*.vue',
            'packages/*/*/resources/js/**/*.vue',
        ],
        ignores: [
            'resources/js/shared/ui/AlertError.vue',
            'resources/js/shared/ui/CardSection.vue',
            'resources/js/shared/ui/DetailItem.vue',
            'resources/js/shared/ui/EmptyState.vue',
            'resources/js/shared/ui/InputError.vue',
            'resources/js/shared/ui/SkeletonList.vue',
            'resources/js/shared/ui/primitives/**',
        ],
        plugins: { nubos },
        rules: {
            'nubos/alert-error-only': 'error',
            'nubos/card-section-heading': 'error',
            'nubos/detail-item-only': 'error',
            'nubos/inline-empty-state': 'error',
            'nubos/no-destructive-text': 'error',
            'nubos/page-shell': 'error',
            'nubos/read-only-notice': 'error',
            'nubos/skeleton-list': 'error',
            'nubos/status-badge-only': 'error',
        },
    },
    {
        files: [
            'resources/js/**/*.{ts,vue}',
            'packages/*/*/resources/js/**/*.{ts,vue}',
        ],
        ignores: [
            '**/*.spec.ts',
            '**/tests/**',
            'resources/js/eslint/**',
            'resources/js/shared/composables/useClipboard.ts',
        ],
        plugins: { nubos },
        rules: {
            'nubos/clipboard-composable': 'error',
        },
    },
    {
        files: [
            'resources/js/**/*.{ts,vue}',
            'packages/*/*/resources/js/**/*.{ts,vue}',
            'packages/*/*/tests/Frontend/**/*.ts',
        ],
        ignores: ['resources/js/eslint/**'],
        plugins: { nubos },
        rules: {
            'nubos/feature-boundaries': 'error',
        },
    },
    {
        ignores: [
            'vendor',
            'node_modules',
            'public',
            'storage',
            'bootstrap/ssr',
            '.claude/**',
            '.unlazy/**',
            'tailwind.config.js',
            'vite.config.ts',
            'vitest.config.ts',
            'resources/js/actions/**',
            'resources/js/shared/ui/primitives/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
    },
    prettier,
    {
        plugins: {
            '@stylistic': stylistic,
        },
        rules: {
            curly: ['error', 'all'],
            '@stylistic/brace-style': [
                'error',
                '1tbs',
                { allowSingleLine: false },
            ],
        },
    },
);
