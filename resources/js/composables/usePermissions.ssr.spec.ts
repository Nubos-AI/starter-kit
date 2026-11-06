// @vitest-environment node
import { describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import { usePermissions } from '@/composables/usePermissions';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: {} }),
}));

describe('usePermissions on the server', () => {
    it('denies without touching the browser', () => {
        expect(typeof window).toBe('undefined');

        const scope = effectScope();
        const permissions = scope.run(() => usePermissions());

        expect(permissions?.can('roles.create')).toBe(false);
        expect(permissions?.canForObjectType('companies', 'view')).toBe(false);
        expect(permissions?.isEscalated.value).toBe(false);
        expect(permissions?.authority.value).toBeNull();
        expect(() => scope.stop()).not.toThrow();
    });
});
