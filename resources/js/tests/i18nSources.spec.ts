import { NodeTypes, parse as parseTemplate } from '@vue/compiler-dom';
import type { RootNode, TemplateChildNode } from '@vue/compiler-dom';
import { parse } from '@vue/compiler-sfc';
import { expect, it } from 'vitest';

const sources = import.meta.glob<string>(
    ['../**/*.vue', '../../../packages/*/*/resources/js/**/*.vue'],
    { eager: true, query: '?raw', import: 'default' },
);

const visibleAttributes = new Set([
    'title',
    'description',
    'label',
    'placeholder',
    'aria-label',
    'alt',
    'empty-text',
    'empty-label',
    'search-placeholder',
    'confirm-label',
    'cancel-label',
    'loading-text',
    'tooltip',
    'text',
    'header',
    'message',
]);

const technicalProtocolIdentifiers = new Set([
    'POST',
    'AUTOMATION_HTTP_ALLOWED_HOSTS',
]);

it('keeps visible template text and static labels in the shared catalogues', () => {
    const remaining: string[] = [];
    function inspect(node: RootNode | TemplateChildNode, file: string): void {
        if (node.type === NodeTypes.TEXT) {
            const text = node.content.replace(/\s+/g, ' ').trim();

            if (
                /\p{L}/u.test(text) &&
                !technicalProtocolIdentifiers.has(text)
            ) {
                remaining.push(`${file}:${node.loc.start.line}: ${text}`);
            }
        }

        if (node.type === NodeTypes.ELEMENT) {
            for (const attribute of node.props) {
                if (
                    attribute.type === NodeTypes.ATTRIBUTE &&
                    visibleAttributes.has(attribute.name) &&
                    attribute.value?.content.trim()
                ) {
                    remaining.push(
                        `${file}:${attribute.loc.start.line}: ${attribute.name}=${attribute.value.content}`,
                    );
                }
            }
        }

        if (node.type === NodeTypes.ROOT || node.type === NodeTypes.ELEMENT) {
            for (const child of node.children) {
                inspect(child, file);
            }
        }
    }
    expect(Object.keys(sources)).toContain('../pages/auth/Login.vue');

    for (const [file, source] of Object.entries(sources)) {
        const { descriptor } = parse(source);

        if (descriptor.template) {
            inspect(parseTemplate(descriptor.template.content), file);
        }
    }

    expect(remaining).toEqual([]);
});
