import ObjectTypesController from '@/actions/App/Http/Controllers/Engine/ObjectTypesController';
import type { NavItem } from '@/types/navigation';

export const OBJECT_TYPE_SECTIONS = {
    details: 'Details',
    fields: 'Felder',
    agingRules: 'Aging-Regeln',
    mergeRules: 'Merge-Regeln',
    permissions: 'Rechte',
} as const;

export type ObjectTypeSectionKey = keyof typeof OBJECT_TYPE_SECTIONS;

export interface ObjectTypeSectionItem {
    title: string;
    href: NavItem['href'] | null;
}

export function objectTypeSectionItems(
    slug: string | null,
): ObjectTypeSectionItem[] {
    if (slug === null) {
        return [
            {
                title: OBJECT_TYPE_SECTIONS.details,
                href: ObjectTypesController.create(),
            },
            { title: OBJECT_TYPE_SECTIONS.fields, href: null },
            { title: OBJECT_TYPE_SECTIONS.agingRules, href: null },
            { title: OBJECT_TYPE_SECTIONS.mergeRules, href: null },
            { title: OBJECT_TYPE_SECTIONS.permissions, href: null },
        ];
    }

    const args = { objectType: slug };

    return [
        {
            title: OBJECT_TYPE_SECTIONS.details,
            href: ObjectTypesController.edit(args),
        },
        {
            title: OBJECT_TYPE_SECTIONS.fields,
            href: ObjectTypesController.fields(args),
        },
        {
            title: OBJECT_TYPE_SECTIONS.agingRules,
            href: ObjectTypesController.agingRules(args),
        },
        {
            title: OBJECT_TYPE_SECTIONS.mergeRules,
            href: ObjectTypesController.mergeRules(args),
        },
        {
            title: OBJECT_TYPE_SECTIONS.permissions,
            href: ObjectTypesController.permissions(args),
        },
    ];
}
