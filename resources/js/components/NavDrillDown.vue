<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed, ref } from 'vue';
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

const { isCurrentUrl } = useCurrentUrl();

function pathToActive(nodes: NavNode[]): NavNode[] | null {
    for (const node of nodes) {
        if (node.href && isCurrentUrl(node.href)) {
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

function initialStack(): Level[] {
    for (const node of props.configuration) {
        const path = pathToActive(node.children ?? []);

        if (path !== null) {
            const levels: Level[] = [
                { label: node.label, nodes: node.children ?? [] },
            ];

            for (const parent of path) {
                levels.push({
                    label: parent.label,
                    nodes: parent.children ?? [],
                });
            }

            return levels;
        }
    }

    return [];
}

const stack = ref<Level[]>(initialStack());
const direction = ref<'forward' | 'back'>('forward');

const depth = computed<number>(() => stack.value.length);
const currentNodes = computed<NavNode[]>(() =>
    depth.value > 0 ? stack.value[depth.value - 1].nodes : [],
);

function toNavItems(nodes: NavNode[]): NavItem[] {
    return nodes.map((node) => ({
        title: node.label,
        href: node.href ?? '#',
        icon: iconFor(node.icon),
    }));
}

function drill(node: NavNode): void {
    if (!node.children || node.children.length === 0) {
        return;
    }

    direction.value = 'forward';
    stack.value = [...stack.value, { label: node.label, nodes: node.children }];
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
                    <NavMain
                        v-for="(section, index) in props.mainSections"
                        :key="index"
                        :items="toNavItems(section.items)"
                        :label="section.label ?? ''"
                    />

                    <SidebarGroup class="mt-auto px-2 py-0">
                        <SidebarMenu>
                            <SidebarMenuItem
                                v-for="node in props.configuration"
                                :key="node.key"
                            >
                                <SidebarMenuButton
                                    v-if="
                                        node.children &&
                                        node.children.length > 0
                                    "
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
                    <SidebarGroup class="px-2 py-0">
                        <SidebarMenu>
                            <SidebarMenuItem>
                                <SidebarMenuButton
                                    class="text-muted-foreground"
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

                    <template v-for="node in currentNodes" :key="node.key">
                        <NavMain
                            v-if="node.group"
                            :items="toNavItems(node.children ?? [])"
                            :label="node.label"
                        />
                        <SidebarGroup v-else class="px-2 py-0">
                            <SidebarMenu>
                                <SidebarMenuItem>
                                    <SidebarMenuButton
                                        v-if="
                                            node.children &&
                                            node.children.length > 0
                                        "
                                        @click="drill(node)"
                                    >
                                        <component
                                            :is="iconFor(node.icon)"
                                            v-if="node.icon"
                                        />
                                        <span>{{ node.label }}</span>
                                        <ChevronRight class="ml-auto" />
                                    </SidebarMenuButton>
                                    <SidebarMenuButton
                                        v-else-if="node.href"
                                        as-child
                                        :is-active="isCurrentUrl(node.href)"
                                    >
                                        <Link :href="node.href">
                                            <component
                                                :is="iconFor(node.icon)"
                                                v-if="node.icon"
                                            />
                                            <span>{{ node.label }}</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            </SidebarMenu>
                        </SidebarGroup>
                    </template>
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
