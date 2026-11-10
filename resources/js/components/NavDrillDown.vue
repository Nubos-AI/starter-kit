<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import NavMain from '@/components/NavMain.vue';
import {
    SidebarGroup,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useI18n } from '@/composables/useI18n';
import { iconFor } from '@/lib/navIcons';
import { bestNavHref, navPath } from '@/lib/navMatch';
import type { NavTarget } from '@/lib/navMatch';
import type { NavItem, NavNode, NavSection } from '@/types';

const { t } = useI18n();

const props = defineProps<{
    mainSections: NavSection[];
    configuration: NavNode[];
}>();

type Level = {
    label: string;
    nodes: NavNode[];
};

const { currentUrl } = useCurrentUrl();

function targetsOf(nodes: NavNode[]): NavTarget[] {
    return nodes.flatMap((node) => [
        ...(node.href ? [{ href: node.href, matches: node.matches }] : []),
        ...targetsOf(node.children ?? []),
    ]);
}

const activeHref = computed<string | null>(() =>
    bestNavHref(
        currentUrl.value,
        targetsOf([
            ...props.mainSections.flatMap((section) => section.items),
            ...props.configuration,
        ]),
    ),
);

function isActive(href: string | undefined): boolean {
    return (
        href !== undefined &&
        activeHref.value !== null &&
        navPath(href) === activeHref.value
    );
}

function pathToActive(nodes: NavNode[]): NavNode[] | null {
    for (const node of nodes) {
        if (isActive(node.href)) {
            return [];
        }

        if (node.children && node.children.length > 0) {
            const deeper = pathToActive(node.children);

            if (deeper !== null) {
                return node.group ? deeper : [node, ...deeper];
            }
        }
    }

    return null;
}

function activeStack(): Level[] | null {
    const path = pathToActive([
        ...props.mainSections.flatMap((section) => section.items),
        ...props.configuration,
    ]);

    return (
        path?.map((node) => ({
            label: node.label,
            nodes: node.children ?? [],
        })) ?? null
    );
}

const stack = ref<Level[]>(activeStack() ?? []);
const direction = ref<'forward' | 'back'>('forward');

const depth = computed<number>(() => stack.value.length);
const currentNodes = computed<NavNode[]>(() =>
    depth.value > 0 ? stack.value[depth.value - 1].nodes : [],
);

watch(activeHref, () => {
    if (pathToActive(currentNodes.value)?.length === 0) {
        return;
    }

    const target = activeStack();

    if (target === null) {
        return;
    }

    direction.value = target.length < depth.value ? 'back' : 'forward';
    stack.value = target;
});

type LevelSegment = {
    key: string;
    label: string | undefined;
    nodes: NavNode[];
};

const levelSegments = computed<LevelSegment[]>(() =>
    currentNodes.value.reduce<LevelSegment[]>((segments, node) => {
        const last = segments[segments.length - 1];

        if (node.group) {
            return [
                ...segments,
                {
                    key: node.key,
                    label: node.label,
                    nodes: node.children ?? [],
                },
            ];
        }

        if (last !== undefined && last.label === undefined) {
            last.nodes.push(node);

            return segments;
        }

        return [
            ...segments,
            { key: node.key, label: undefined, nodes: [node] },
        ];
    }, []),
);

function withoutStraySeparators(nodes: NavNode[]): NavNode[] {
    return nodes.filter(
        (node, index) =>
            !node.separator ||
            (nodes.slice(0, index).some((before) => !before.separator) &&
                nodes[index + 1] !== undefined &&
                !nodes[index + 1].separator),
    );
}

function toNavItems(nodes: NavNode[]): NavItem[] {
    return withoutStraySeparators(nodes).map((node) => ({
        key: node.key,
        hasChildren: (node.children ?? []).length > 0,
        title: node.label,
        href: node.href ?? '#',
        icon: iconFor(node.icon),
        isActive: isActive(node.href),
        isSeparator: node.separator === true,
    }));
}

function drill(node: NavNode): void {
    if (!node.children || node.children.length === 0) {
        return;
    }

    direction.value = 'forward';
    stack.value = [...stack.value, { label: node.label, nodes: node.children }];
}

function drillInto(nodes: NavNode[], key: string): void {
    const node = nodes.find((candidate) => candidate.key === key);

    if (node) {
        drill(node);
    }
}

function back(): void {
    if (stack.value.length === 0) {
        return;
    }

    direction.value = 'back';
    stack.value = stack.value.slice(0, -1);
}
</script>

<template>
    <div class="relative flex min-h-0 flex-1 flex-col overflow-hidden">
        <Transition :name="`drill-${direction}`">
            <div
                :key="depth"
                class="drill-panel flex min-h-0 flex-1 flex-col bg-sidebar"
            >
                <template v-if="depth === 0">
                    <div
                        data-nav-scroll
                        class="flex min-h-0 flex-1 flex-col overflow-y-auto"
                    >
                        <NavMain
                            v-for="(section, index) in props.mainSections"
                            :key="index"
                            :items="toNavItems(section.items)"
                            :label="section.label ?? ''"
                            @drill="(key) => drillInto(section.items, key)"
                        />
                    </div>

                    <SidebarGroup class="shrink-0 px-2 py-0">
                        <SidebarMenu class="gap-0.5">
                            <SidebarMenuItem
                                v-for="node in props.configuration"
                                :key="node.key"
                            >
                                <SidebarMenuButton
                                    v-if="
                                        node.children &&
                                        node.children.length > 0
                                    "
                                    class="h-7"
                                    @click="drill(node)"
                                >
                                    <component
                                        :is="iconFor(node.icon)"
                                        v-if="node.icon"
                                    />
                                    <span>{{ node.label }}</span>
                                    <ChevronRight class="ml-auto" />
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        </SidebarMenu>
                    </SidebarGroup>
                </template>

                <template v-else>
                    <SidebarGroup class="shrink-0 px-2 pt-0 pb-3">
                        <SidebarMenu>
                            <SidebarMenuItem>
                                <SidebarMenuButton
                                    class="h-7 text-muted-foreground"
                                    :aria-label="
                                        t('i18n.components.nav_drill_down.back')
                                    "
                                    @click="back"
                                >
                                    <ChevronLeft />
                                    <span>{{
                                        t('i18n.components.nav_drill_down.back')
                                    }}</span>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        </SidebarMenu>
                    </SidebarGroup>

                    <div
                        data-nav-scroll
                        class="flex min-h-0 flex-1 flex-col overflow-y-auto"
                    >
                        <NavMain
                            v-for="segment in levelSegments"
                            :key="segment.key"
                            :items="toNavItems(segment.nodes)"
                            :label="segment.label"
                            @drill="(key) => drillInto(segment.nodes, key)"
                        />
                    </div>
                </template>
            </div>
        </Transition>
    </div>
</template>

<style scoped>
.drill-forward-enter-active,
.drill-back-enter-active {
    transition: transform 240ms ease;
    will-change: transform;
}

.drill-forward-leave-active,
.drill-back-leave-active {
    position: absolute;
    inset: 0;
    pointer-events: none;
    transition: transform 240ms ease;
    will-change: transform;
}

.drill-forward-enter-from {
    transform: translateX(100%);
}

.drill-forward-leave-to {
    transform: translateX(-100%);
}

.drill-back-enter-from {
    transform: translateX(-100%);
}

.drill-back-leave-to {
    transform: translateX(100%);
}
</style>
