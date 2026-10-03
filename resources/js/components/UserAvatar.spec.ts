import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import UserAvatar from '@/components/UserAvatar.vue';

function mountAvatar(name: string) {
    return mount(UserAvatar, { props: { name } });
}

function toneOf(name: string): string {
    return mountAvatar(name).find('[title]').attributes('class') ?? '';
}

describe('UserAvatar', () => {
    it('renders the initials of a full name', () => {
        expect(mountAvatar('Rita Vertrieb').text()).toBe('RV');
    });

    it('renders a single initial for a one-word name', () => {
        expect(mountAvatar('Rita').text()).toBe('R');
    });

    it('exposes the full name as a title', () => {
        expect(
            mountAvatar('Rita Vertrieb').find('[title]').attributes('title'),
        ).toBe('Rita Vertrieb');
    });

    it('keeps the tone stable for the same name', () => {
        expect(toneOf('Rita Vertrieb')).toBe(toneOf('Rita Vertrieb'));
    });

    it('assigns different tones to different names', () => {
        const tones = new Set(
            [
                'Rita Vertrieb',
                'Sven Support',
                'Mia Marketing',
                'Bea Buchhaltung',
            ].map(toneOf),
        );

        expect(tones.size).toBeGreaterThan(1);
    });
});
