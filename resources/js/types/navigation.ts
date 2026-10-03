import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type BreadcrumbItem = {
    title: string;
    href?: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
};

export type NavNode = {
    key: string;
    label: string;
    icon?: string;
    href?: string;
    group?: boolean;
    children?: NavNode[];
};

export type NavSection = {
    label?: string;
    items: NavNode[];
};
