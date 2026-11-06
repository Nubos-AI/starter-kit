import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { computed, defineComponent, h, reactive } from 'vue';
import UiExtensionPoint from '@/components/modules/UiExtensionPoint.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { provideUiExtensions } from '@/composables/useUiExtensions';
import type { UiExtension, UiExtensionState } from '@/types/modules';

vi.mock('@/lib/modules', () => {
    const loaders: Record<string, () => Promise<{ default: unknown }>> = {
        'vendor/example:first.vue': async () => ({
            default: {
                props: ['label', 'context'],
                template:
                    '<button data-first>{{ label ?? "First" }} {{ context.recordId }}</button>',
            },
        }),
        'vendor/second:second.vue': async () => ({
            default: { template: '<button data-second>Second</button>' },
        }),
        'vendor/example:tab.vue': async () => ({
            default: defineComponent({
                setup: () => () =>
                    h(TabsTrigger, { value: 'extension' }, () => 'Package tab'),
            }),
        }),
        'vendor/example:panel.vue': async () => ({
            default: defineComponent({
                props: ['context'],
                setup: (props) => () =>
                    h(
                        TabsContent,
                        { value: 'extension' },
                        () => `Package content ${props.context.recordId}`,
                    ),
            }),
        }),
        'vendor/example:menu.vue': async () => ({
            default: defineComponent({
                props: ['context'],
                setup: (props) => () =>
                    h(
                        DropdownMenuItem,
                        {
                            'data-package-menu': '',
                            onSelect: () =>
                                props.context.select(props.context.recordId),
                        },
                        () => 'Package action',
                    ),
            }),
        }),
    };

    return {
        moduleComponent: (module: string, component: string) =>
            loaders[`${module}:${component}`],
    };
});

const sharedExtensions: UiExtension[] = [
    {
        id: 'vendor/example.first',
        module: 'vendor/example',
        point: 'header.actions',
        component: 'first.vue',
        order: 100,
    },
    {
        id: 'vendor/second.second',
        module: 'vendor/second',
        point: 'header.actions',
        component: 'second.vue',
        order: 200,
    },
    {
        id: 'vendor/example.tab',
        module: 'vendor/example',
        point: 'tabs.example.triggers',
        component: 'tab.vue',
        order: 100,
    },
    {
        id: 'vendor/example.panel',
        module: 'vendor/example',
        point: 'tabs.example.panels',
        component: 'panel.vue',
        order: 100,
    },
    {
        id: 'vendor/example.menu',
        module: 'vendor/example',
        point: 'menus.example',
        component: 'menu.vue',
        order: 100,
    },
];

const state = reactive<UiExtensionState>({
    modules: [],
    extensions: [],
    options: {},
    overrides: {},
    page: {},
});

function mountPoint(name = 'header.actions') {
    return mount(
        defineComponent({
            setup() {
                provideUiExtensions(computed(() => state));

                return () =>
                    h(UiExtensionPoint, {
                        name,
                        context: { recordId: 'record-123' },
                    });
            },
        }),
    );
}

beforeEach(() => {
    state.modules = ['vendor/example', 'vendor/second'];
    state.extensions = sharedExtensions;
    state.overrides = {};
});

describe('generic package UI contributions', () => {
    it('renders multiple independent packages in order and provides the local context', async () => {
        const wrapper = mountPoint();
        await flushPromises();
        expect(wrapper.findAll('button').map((item) => item.text())).toEqual([
            'First record-123',
            'Second',
        ]);
    });

    it('hides a single contribution without disabling its package', async () => {
        state.overrides = { 'vendor/example.first': { enabled: false } };
        const wrapper = mountPoint();
        await flushPromises();
        expect(wrapper.find('[data-first]').exists()).toBe(false);
        expect(wrapper.find('[data-second]').exists()).toBe(true);
    });

    it('moves a contribution to another point and passes customer label props', async () => {
        state.overrides = {
            'vendor/example.first': {
                point: 'sidebar.footer',
                props: { label: 'Custom' },
            },
        };
        const header = mountPoint();
        const footer = mountPoint('sidebar.footer');
        await flushPromises();
        expect(header.find('[data-first]').exists()).toBe(false);
        expect(footer.get('[data-first]').text()).toBe('Custom record-123');
    });

    it('allows a customer to reorder contributions', async () => {
        state.overrides = { 'vendor/second.second': { order: 1 } };
        const wrapper = mountPoint();
        await flushPromises();
        expect(wrapper.findAll('button').map((item) => item.text())).toEqual([
            'Second',
            'First record-123',
        ]);
    });

    it('renders no contribution the server does not share', async () => {
        state.modules = [];
        state.extensions = [];
        const wrapper = mountPoint();
        await flushPromises();
        expect(wrapper.findAll('button')).toHaveLength(0);
    });
});

it('opens a package tab and its matching panel with the record context', async () => {
    const wrapper = mount(
        defineComponent({
            setup() {
                provideUiExtensions(computed(() => state));

                return () =>
                    h(
                        Tabs,
                        {
                            defaultValue: 'core',
                            extensionPoint: 'tabs.example',
                            extensionContext: { recordId: 'record-123' },
                        },
                        () => [
                            h(TabsList, {}, () =>
                                h(
                                    TabsTrigger,
                                    { value: 'core' },
                                    () => 'Core tab',
                                ),
                            ),
                            h(
                                TabsContent,
                                { value: 'core' },
                                () => 'Core content',
                            ),
                        ],
                    );
            },
        }),
    );
    await flushPromises();
    const trigger = wrapper
        .findAll('[role="tab"]')
        .find((tab) => tab.text() === 'Package tab');
    expect(trigger).toBeDefined();
    await trigger!.trigger('mousedown', { button: 0, ctrlKey: false });
    await flushPromises();
    expect(trigger!.attributes('aria-selected')).toBe('true');
    expect(wrapper.get('[role="tabpanel"][data-state="active"]').text()).toBe(
        'Package content record-123',
    );
});

it('executes a package menu action with the surrounding record context', async () => {
    const select = vi.fn();
    const wrapper = mount(
        defineComponent({
            setup() {
                provideUiExtensions(computed(() => state));

                return () =>
                    h(DropdownMenu, { defaultOpen: true }, () => [
                        h(DropdownMenuTrigger, {}, () => 'Menu'),
                        h(DropdownMenuContent, {
                            forceMount: true,
                            extensionPoint: 'menus.example',
                            extensionContext: {
                                recordId: 'record-123',
                                select,
                            },
                        }),
                    ]);
            },
        }),
        { attachTo: document.body },
    );

    try {
        await flushPromises();
        await vi.waitFor(() =>
            expect(
                document.querySelector('[data-package-menu]'),
            ).not.toBeNull(),
        );
        document.querySelector<HTMLElement>('[data-package-menu]')?.click();
        await flushPromises();
        expect(select).toHaveBeenCalledWith('record-123');
    } finally {
        wrapper.unmount();
    }
});
