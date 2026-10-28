import { usePage } from '@inertiajs/vue3';
import type {
    ComputedRef,
    InjectionKey,
    MaybeRefOrGetter,
    ShallowRef,
} from 'vue';
import {
    computed,
    getCurrentInstance,
    inject,
    provide,
    shallowRef,
    toValue,
} from 'vue';
import { bestNavHref, navPath } from '@/lib/navMatch';
import type { BreadcrumbItem, NavNode, NavSection } from '@/types/navigation';

interface NavCandidate {
    href: string;
    title: string;
    ancestors: string[];
}

interface RegisteredTail {
    component: string | undefined;
    source: MaybeRefOrGetter<BreadcrumbItem[]>;
}

export interface BreadcrumbTrail {
    items: ComputedRef<BreadcrumbItem[]>;
    backHref: ComputedRef<string | null>;
}

type TailRef = ShallowRef<RegisteredTail | null>;

const BREADCRUMB_TAIL: InjectionKey<TailRef> = Symbol('breadcrumb-tail');

const detachedTail: TailRef = shallowRef(null);

export function provideBreadcrumbTail(): void {
    provide(BREADCRUMB_TAIL, shallowRef(null));
}

function tailRef(): TailRef {
    if (getCurrentInstance() === null) {
        return detachedTail;
    }

    return inject(BREADCRUMB_TAIL, detachedTail);
}

function isNavNode(value: unknown): value is NavNode {
    return (
        typeof value === 'object' &&
        value !== null &&
        typeof (value as NavNode).label === 'string'
    );
}

function collect(
    nodes: unknown,
    ancestors: string[],
    candidates: NavCandidate[],
): void {
    if (!Array.isArray(nodes)) {
        return;
    }

    for (const node of nodes) {
        if (!isNavNode(node)) {
            continue;
        }

        if (typeof node.href === 'string' && node.href !== '') {
            candidates.push({
                href: node.href,
                title: node.label,
                ancestors,
            });
        }

        collect(node.children, [...ancestors, node.label], candidates);
    }
}

function navCandidates(props: unknown): NavCandidate[] {
    if (props === null || typeof props !== 'object') {
        return [];
    }

    const navigation = (props as { navigation?: unknown }).navigation;

    if (navigation === null || typeof navigation !== 'object') {
        return [];
    }

    const { main, configuration } = navigation as {
        main?: unknown;
        configuration?: unknown;
    };

    const candidates: NavCandidate[] = [];

    if (Array.isArray(main)) {
        for (const section of main as NavSection[]) {
            const label = section?.label;

            collect(
                section?.items,
                label === undefined || label === '' ? [] : [label],
                candidates,
            );
        }
    }

    collect(configuration, [], candidates);

    return candidates;
}

function navTrail(props: unknown, path: string): BreadcrumbItem[] {
    const candidates = navCandidates(props);
    const best = bestNavHref(
        path,
        candidates.map((candidate) => candidate.href),
    );
    const match = candidates.find(
        (candidate) => best !== null && navPath(candidate.href) === best,
    );

    if (match === undefined) {
        return [];
    }

    return [
        ...match.ancestors.map((title) => ({ title })),
        { title: match.title, href: match.href },
    ];
}

function dropRepeatedHrefs(items: BreadcrumbItem[]): BreadcrumbItem[] {
    return items.filter((item, index) => {
        const previous = items[index - 1];

        return (
            index === 0 ||
            item.href === undefined ||
            navPath(item.href) !== navPath(previous?.href)
        );
    });
}

export function usePageBreadcrumbs(
    items: MaybeRefOrGetter<BreadcrumbItem[]>,
): void {
    const page = usePage();

    tailRef().value = { component: page?.component, source: items };
}

export function clearPageBreadcrumbs(): void {
    detachedTail.value = null;
}

export function useBreadcrumbTrail(): BreadcrumbTrail {
    const page = usePage();
    const registeredTail = tailRef();

    const items = computed<BreadcrumbItem[]>(() => {
        const path = navPath(page?.url);
        const tail = registeredTail.value;

        return dropRepeatedHrefs([
            ...navTrail(page?.props, path),
            ...(tail !== null && tail.component === page?.component
                ? toValue(tail.source)
                : []),
        ]);
    });

    const backHref = computed<string | null>(() => {
        const parents = items.value.slice(0, -1);

        for (let index = parents.length - 1; index >= 0; index--) {
            const href = parents[index].href;

            if (typeof href === 'string' && href !== '') {
                return href;
            }
        }

        return null;
    });

    return { items, backHref };
}
