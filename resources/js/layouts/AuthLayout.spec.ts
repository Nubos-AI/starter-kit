import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import AuthLayout from '@/layouts/AuthLayout.vue';

const page = vi.hoisted(() => ({
    component: 'Welcome',
    props: {
        uiModules: ['vendor/example'],
        uiExtensions: [
            {
                id: 'vendor/example.welcome',
                module: 'vendor/example',
                point: 'pages.Welcome.after',
                component: 'welcome.vue',
                order: 100,
            },
        ],
        uiOptions: {},
        uiExtensionOverrides: {},
        tenantName: 'Acme',
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    Link: { template: '<a><slot /></a>' },
}));

vi.mock('@/lib/modules', () => ({
    moduleComponent: () => async () => ({
        default: {
            props: ['context'],
            template:
                '<div data-package-welcome>{{ context.page.tenantName }}</div>',
        },
    }),
}));

describe('the auth layout', () => {
    it('lets an installed package add to the page it frames through the page extension point', async () => {
        const wrapper = mount(AuthLayout, {
            props: { title: 'Willkommen' },
            slots: { default: '<p data-page-content>Inhalt</p>' },
        });

        await flushPromises();

        expect(
            wrapper
                .find('[data-extension-point="pages.Welcome.after"]')
                .exists(),
        ).toBe(true);
        expect(wrapper.get('[data-package-welcome]').text()).toBe('Acme');
        expect(wrapper.find('[data-page-content]').exists()).toBe(true);
    });

    it('leaves the page alone while no package targets it', async () => {
        page.props.uiExtensions = [];

        const wrapper = mount(AuthLayout, { props: { title: 'Willkommen' } });

        await flushPromises();

        expect(wrapper.find('[data-package-welcome]').exists()).toBe(false);
    });
});
