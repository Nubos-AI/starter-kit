import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type BreadcrumbItem = {
    title: string;
    href?: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    key?: string;
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
    hasChildren?: boolean;
    isSeparator?: boolean;
};

export type NavNode = {
    key: string;
    label: string;
    icon?: string;
    href?: string;
    matches?: string[];
    group?: boolean;
    separator?: boolean;
    children?: NavNode[];
};

export type NavSection = {
    label?: string;
    items: NavNode[];
};

export interface SectionNavEntry {
    key: string;
    title: string;
    href?: NonNullable<InertiaLinkProps['href']> | null;
    disabledReason?: string | null;
    hasErrors?: boolean;
}
