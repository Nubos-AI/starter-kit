import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import AppLogo from '@/components/AppLogo.vue';

describe('AppLogo', () => {
    it('renders the Nubos logo image', () => {
        const logo = mount(AppLogo).find('[data-app-logo-image]');

        expect(logo.element.tagName).toBe('IMG');
        expect(logo.attributes('src')).toContain('nubos-logo');
        expect(logo.attributes('alt')).toBe('');
    });

    it('names the product Nubos Starterkit', () => {
        expect(mount(AppLogo).find('[data-app-logo-name]').text()).toBe(
            'Nubos Starterkit',
        );
    });
});
