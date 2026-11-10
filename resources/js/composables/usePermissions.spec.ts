import { beforeEach, describe, expect, it, vi } from 'vitest';
import { usePermissions } from '@/composables/usePermissions';

const { pageState } = vi.hoisted(() => ({
    pageState: {
        props: {
            auth: {
                user: null,
                can: {} as Record<string, unknown>,
                authority: null as unknown,
            },
        },
        url: '/dashboard',
    } as { props: unknown; url: string },
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => pageState,
}));

function grant(...names: string[]): void {
    pageState.props = {
        auth: {
            user: null,
            can: Object.fromEntries(names.map((name) => [name, true])),
            authority: null,
        },
    };
}

beforeEach(() => {
    grant();
});

describe('usePermissions', () => {
    it('allows a granted permission', () => {
        grant('roles.create');

        expect(usePermissions().can('roles.create')).toBe(true);
    });

    it('denies a permission the map does not mention', () => {
        grant('roles.view');

        expect(usePermissions().can('roles.create')).toBe(false);
    });

    it.each<[string, unknown]>([
        [
            'the map is empty',
            { auth: { user: null, can: {}, authority: null } },
        ],
        ['auth is missing', {}],
        ['props are missing', undefined],
        [
            'the map itself is not an object',
            { auth: { user: null, can: 'everything', authority: null } },
        ],
    ])('denies everything when %s', (_label, props) => {
        pageState.props = props;

        expect(usePermissions().can('roles.create')).toBe(false);
    });

    it.each([
        ['the string true', 'true'],
        ['the number one', 1],
        ['an object', {}],
        ['an array', []],
        ['null', null],
        ['undefined', undefined],
        ['the string false', 'false'],
        ['zero', 0],
        ['an empty string', ''],
    ])(
        'denies when the verdict is %s rather than the boolean true',
        (_label, verdict) => {
            pageState.props = {
                auth: {
                    user: null,
                    can: { 'roles.create': verdict },
                    authority: null,
                },
            };

            expect(usePermissions().can('roles.create')).toBe(false);
        },
    );

    it('composes the object type key from slug and ability', () => {
        grant('companies.rules.manage');

        const { canForObjectType } = usePermissions();

        expect(canForObjectType('companies', 'rules.manage')).toBe(true);
        expect(canForObjectType('contacts', 'rules.manage')).toBe(false);
        expect(canForObjectType('companies', 'audit.view')).toBe(false);
    });

    it('reads the map on every question instead of snapshotting it', () => {
        const { can } = usePermissions();

        expect(can('roles.create')).toBe(false);

        grant('roles.create');

        expect(can('roles.create')).toBe(true);
    });

    it.each(['super_admin', 'scope_admin'])(
        'reports a %s as escalated',
        (authorityValue) => {
            pageState.props = {
                auth: { user: null, can: {}, authority: authorityValue },
            };

            const { authority, isEscalated } = usePermissions();

            expect(authority.value).toBe(authorityValue);
            expect(isEscalated.value).toBe(true);
        },
    );

    it('reports an ordinary user as not escalated', () => {
        const { authority, isEscalated } = usePermissions();

        expect(authority.value).toBeNull();
        expect(isEscalated.value).toBe(false);
    });

    it('refuses an authority value it does not know', () => {
        pageState.props = { auth: { user: null, can: {}, authority: 'root' } };

        const { authority, isEscalated } = usePermissions();

        expect(authority.value).toBeNull();
        expect(isEscalated.value).toBe(false);
    });
});
