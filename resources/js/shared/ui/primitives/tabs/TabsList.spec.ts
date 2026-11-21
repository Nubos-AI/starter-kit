import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, nextTick, ref } from 'vue';
import {
    Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
} from '@/shared/ui/primitives/tabs';

const tabValues = ['one', 'two', 'three', 'four', 'five'];
const triggerWidth = 100;
const overflowTriggerWidth = 32;

let containerWidth = 0;
let resizeCallbacks: Array<() => void> = [];
let disconnectedObservers = 0;

class ResizeObserverDouble implements ResizeObserver {
    constructor(callback: ResizeObserverCallback) {
        resizeCallbacks.push(() => callback([], this));
    }

    observe(): void {}

    unobserve(): void {}

    disconnect(): void {
        disconnectedObservers += 1;
    }
}

function widthOf(element: Element): number {
    if (element.matches('[data-tabs-overflow]')) {
        return containerWidth;
    }

    if (element.matches('[data-tabs-overflow-trigger]')) {
        return overflowTriggerWidth;
    }

    if (element.matches('[role="tab"]')) {
        return triggerWidth;
    }

    return 0;
}

const dropdownStubs = {
    DropdownMenu: { template: '<div><slot /></div>' },
    DropdownMenuTrigger: { template: '<div><slot /></div>' },
    DropdownMenuContent: {
        template: '<div data-overflow-menu><slot /></div>',
    },
    DropdownMenuItem: {
        props: ['disabled'],
        emits: ['select'],
        template:
            '<button type="button" :disabled="disabled" @click="$emit(\'select\')"><slot /></button>',
    },
};

const Harness = defineComponent({
    components: { Tabs, TabsContent, TabsList, TabsTrigger },
    setup() {
        const active = ref('one');

        return { active, tabValues };
    },
    template: `
        <Tabs v-model="active">
            <TabsList overflow class="w-full">
                <TabsTrigger
                    v-for="tab in tabValues"
                    :key="tab"
                    :value="tab"
                    :data-test-tab="tab"
                >
                    Label {{ tab }}
                </TabsTrigger>
            </TabsList>
            <TabsContent v-for="tab in tabValues" :key="tab" :value="tab">
                <div :data-test-panel="tab" />
            </TabsContent>
        </Tabs>
    `,
});

async function mountRow(width: number): Promise<VueWrapper> {
    containerWidth = width;

    const wrapper = mount(Harness, {
        global: { stubs: dropdownStubs },
    });

    await nextTick();

    return wrapper;
}

function hiddenTriggers(wrapper: VueWrapper): string[] {
    return wrapper
        .findAll('[data-test-tab]')
        .filter((trigger) => trigger.attributes('hidden') !== undefined)
        .map((trigger) => trigger.attributes('data-test-tab') ?? '');
}

function overflowItems(wrapper: VueWrapper): string[] {
    return wrapper
        .findAll('[data-tabs-overflow-item]')
        .map((item) => item.attributes('data-tabs-overflow-item') ?? '');
}

async function resizeTo(width: number): Promise<void> {
    containerWidth = width;

    for (const callback of resizeCallbacks) {
        callback();
    }

    await nextTick();
}

describe('TabsList overflow', () => {
    beforeEach(() => {
        containerWidth = 0;
        resizeCallbacks = [];
        disconnectedObservers = 0;
        vi.stubGlobal('ResizeObserver', ResizeObserverDouble);
        vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(
            function (this: Element): DOMRect {
                const width = widthOf(this);

                return {
                    x: 0,
                    y: 0,
                    top: 0,
                    left: 0,
                    right: width,
                    bottom: 0,
                    width,
                    height: 0,
                    toJSON: () => ({}),
                };
            },
        );
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('keeps every tab inline while the row is wide enough', async () => {
        const wrapper = await mountRow(500);

        expect(hiddenTriggers(wrapper)).toEqual([]);
        expect(overflowItems(wrapper)).toEqual([]);
        expect(
            wrapper
                .get('[data-tabs-overflow-trigger]')
                .attributes('aria-hidden'),
        ).toBe('true');
    });

    it('moves the tabs that do not fit into the dropdown in their order', async () => {
        const wrapper = await mountRow(300);

        expect(hiddenTriggers(wrapper)).toEqual(['three', 'four', 'five']);
        expect(overflowItems(wrapper)).toEqual(['three', 'four', 'five']);
        expect(
            wrapper.get('[data-tabs-overflow-item="four"]').text(),
        ).toBe('Label four');

        const overflowTrigger = wrapper.get('[data-tabs-overflow-trigger]');

        expect(overflowTrigger.attributes('aria-hidden')).toBeUndefined();
        expect(overflowTrigger.attributes('aria-label')).toBe(
            'Weitere Reiter',
        );
        expect(overflowTrigger.attributes('data-state')).toBe('inactive');
    });

    it('activates a tab chosen from the dropdown and marks the dropdown as active', async () => {
        const wrapper = await mountRow(300);

        await wrapper.get('[data-tabs-overflow-item="four"]').trigger('click');

        expect(wrapper.find('[data-test-panel="four"]').exists()).toBe(true);
        expect(wrapper.find('[data-test-panel="one"]').exists()).toBe(false);
        expect(
            wrapper
                .get('[data-tabs-overflow-trigger]')
                .attributes('data-state'),
        ).toBe('active');
        expect(
            wrapper
                .get('[data-tabs-overflow-item="four"]')
                .attributes('data-state'),
        ).toBe('active');
        expect(
            wrapper
                .get('[data-tabs-overflow-item="three"]')
                .attributes('data-state'),
        ).toBe('inactive');
    });

    it('restores the tabs inline once the row grows again', async () => {
        const wrapper = await mountRow(300);

        expect(hiddenTriggers(wrapper)).toHaveLength(3);

        await resizeTo(600);

        expect(hiddenTriggers(wrapper)).toEqual([]);
        expect(overflowItems(wrapper)).toEqual([]);
        expect(
            wrapper
                .get('[data-tabs-overflow-trigger]')
                .attributes('aria-hidden'),
        ).toBe('true');
    });

    it('hides further tabs when the row shrinks', async () => {
        const wrapper = await mountRow(600);

        await resizeTo(250);

        expect(hiddenTriggers(wrapper)).toEqual([
            'three',
            'four',
            'five',
        ]);

        await resizeTo(160);

        expect(hiddenTriggers(wrapper)).toEqual([
            'two',
            'three',
            'four',
            'five',
        ]);
    });

    it('stops observing the row when it unmounts', async () => {
        const wrapper = await mountRow(300);

        wrapper.unmount();

        expect(disconnectedObservers).toBe(1);
    });

    it('leaves a row without the overflow option untouched', () => {
        const Plain = defineComponent({
            components: { Tabs, TabsList, TabsTrigger },
            template: `
                <Tabs default-value="one">
                    <TabsList>
                        <TabsTrigger value="one">One</TabsTrigger>
                    </TabsList>
                </Tabs>
            `,
        });

        const wrapper = mount(Plain, { global: { stubs: dropdownStubs } });

        expect(wrapper.find('[data-tabs-overflow]').exists()).toBe(false);
        expect(wrapper.find('[data-tabs-overflow-trigger]').exists()).toBe(
            false,
        );
        expect(resizeCallbacks).toHaveLength(0);
    });
});
