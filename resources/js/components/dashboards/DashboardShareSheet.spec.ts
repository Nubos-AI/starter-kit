import type { VueWrapper } from '@vue/test-utils';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import DashboardsController from '@/actions/App/Http/Controllers/Dashboards/DashboardsController';
import DashboardShareOptionsController from '@/actions/App/Http/Controllers/Dashboards/DashboardShareOptionsController';
import DashboardSharesController from '@/actions/App/Http/Controllers/Dashboards/DashboardSharesController';
import DashboardShareSheet from '@/components/dashboards/DashboardShareSheet.vue';
import type { Checkbox } from '@/components/ui/checkbox';
import type { Combobox } from '@/components/ui/combobox';
import {
    OPTIONS_LOAD_ERROR_MESSAGE,
    SHARE_CREATE_ERROR_MESSAGE,
    SHARE_REVOKE_ERROR_MESSAGE,
    SHARES_LOAD_ERROR_MESSAGE,
} from '@/composables/useDashboardShares';
import { comboboxStubs, selectStubs } from '@/tests/selectStubs';
import type { DashboardRow } from '@/types/dashboards';
import { DEFINER_SHARE_WARNING } from '@/types/dashboards';
import type { SelectOption } from '@/types/ui';
import { setUrlDefaults } from '@/wayfinder';

const inertia = vi.hoisted(() => ({
    visit: vi.fn(),
    put: vi.fn(),
    post: vi.fn(),
    pageState: {
        url: '/nubos/dashboards/01DASHBOARD00000000000001',
        props: {
            auth: {
                user: null,
                can: {} as Record<string, boolean>,
                authority: null as string | null,
            },
        },
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: {
        on: vi.fn(() => vi.fn()),
        visit: inertia.visit,
        put: inertia.put,
        post: inertia.post,
    },
    usePage: () => inertia.pageState,
}));

const { toastError } = vi.hoisted(() => ({ toastError: vi.fn() }));

vi.mock('vue-sonner', () => ({
    toast: { error: toastError, success: vi.fn() },
}));

const DASHBOARD_ID = '01DASHBOARD00000000000001';

const ACTIVE_TEAM = 'nubos';

const SERVER_ERROR_TEXT = 'SERVERMELDUNG_MANDANTENWEIT';

const ENGLISH_WORDS =
    /\b(the|this|that|your|share|link|token|copy|everyone)\b/i;

const PUBLIC_ACCESS_WORDS = /\b(Link|Token|Kopieren|kopieren|öffentlich)\b/;

const USER_OPTIONS: SelectOption[] = [
    {
        value: '01USER00000000000000001A',
        label: 'Anna Albers',
        description: 'anna@nubos.de',
        avatar: { name: 'Anna Albers' },
    },
    {
        value: '01USER00000000000000002B',
        label: 'Bernd Bauer',
        description: 'bernd@nubos.de',
        avatar: { name: 'Bernd Bauer' },
    },
];

const TEAM_OPTIONS: SelectOption[] = [
    { value: '01TEAM00000000000000001A', label: 'Vertrieb' },
];

const ROLE_OPTIONS: SelectOption[] = [
    { value: '01ROLE00000000000000001A', label: 'Sachbearbeitung' },
];

interface WireShare {
    id: string;
    grantee_type: string | null;
    grantee_id: string;
    grantee_name: string | null;
    can_edit: boolean;
    granted_at: string | null;
}

interface ShareFetchOptions {
    shares?: WireShare[];
    sharesStatus?: number;
    optionsStatus?: number;
    createStatus?: number;
    revokeStatus?: number;
    tenantWideStatus?: number;
}

type FetchMock = ReturnType<typeof vi.fn>;

type Wrapper = VueWrapper;

setUrlDefaults({ activeTeam: ACTIVE_TEAM });

const passthrough = { template: '<div><slot /></div>' };

const CheckboxStub = {
    props: ['modelValue', 'disabled'],
    emits: ['update:modelValue'],
    template:
        '<button type="button" :disabled="disabled" @click="$emit(\'update:modelValue\', !modelValue)"><slot /></button>',
};

const stubs = {
    ...selectStubs,
    ...comboboxStubs,
    Checkbox: CheckboxStub,
    Sheet: {
        name: 'Sheet',
        props: ['open'],
        emits: ['update:open'],
        template: '<div><slot /></div>',
    },
    SheetContent: passthrough,
    SheetHeader: passthrough,
    SheetTitle: { template: '<h2><slot /></h2>' },
    SheetDescription: { template: '<p><slot /></p>' },
    Dialog: passthrough,
    DialogContent: passthrough,
    DialogHeader: passthrough,
    DialogFooter: passthrough,
    DialogTitle: passthrough,
    DialogDescription: passthrough,
};

function dashboard(overrides: Partial<DashboardRow> = {}): DashboardRow {
    return {
        id: DASHBOARD_ID,
        name: 'Vertriebsübersicht',
        description: 'Kennzahlen des laufenden Quartals',
        owner_id: '01USER00000000000000001A',
        is_owner: true,
        is_tenant_wide: false,
        is_default: false,
        can_update: true,
        can_delete: true,
        can_share: true,
        update_reason: null,
        delete_reason: null,
        share_reason: null,
        has_definer_widget: false,
        updated_at: '2026-08-10T14:23:45+02:00',
        ...overrides,
    };
}

function share(overrides: Partial<WireShare> = {}): WireShare {
    return {
        id: 'share-1',
        grantee_type: 'user',
        grantee_id: USER_OPTIONS[0].value,
        grantee_name: 'Anna Albers',
        can_edit: false,
        granted_at: '2026-08-10T14:00:00+02:00',
        ...overrides,
    };
}

function jsonResponse(status: number, body: unknown): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    } as unknown as Response;
}

function pathOf(url: string): string {
    return new URL(url, 'http://localhost').pathname;
}

function sharesRoute(): string {
    return DashboardSharesController.index.url({ dashboard: DASHBOARD_ID });
}

function revokeRoute(shareId: string): string {
    return DashboardSharesController.destroy.url({
        dashboard: DASHBOARD_ID,
        share: shareId,
    });
}

function optionsRoute(): string {
    return DashboardShareOptionsController.url({ dashboard: DASHBOARD_ID });
}

function dashboardRoute(): string {
    return DashboardsController.update.url({ dashboard: DASHBOARD_ID });
}

function callsTo(
    fetchMock: FetchMock,
    method: string,
    route: string,
): Array<[string, RequestInit]> {
    return fetchMock.mock.calls.filter(([url, init]) => {
        const used = (init as RequestInit | undefined)?.method ?? 'GET';

        return used === method && pathOf(String(url)) === pathOf(route);
    }) as Array<[string, RequestInit]>;
}

function bodyOf(call: [string, RequestInit]): Record<string, unknown> {
    const body = call[1].body;

    if (typeof body !== 'string') {
        throw new Error('the share request carries no JSON body');
    }

    return JSON.parse(body) as Record<string, unknown>;
}

function shareFetch(options: ShareFetchOptions = {}): FetchMock {
    const mock = vi.fn((url: string, init?: RequestInit) => {
        const method = init?.method ?? 'GET';
        const path = pathOf(String(url));

        if (method === 'GET' && path === pathOf(optionsRoute())) {
            const status = options.optionsStatus ?? 200;

            return Promise.resolve(
                jsonResponse(
                    status,
                    status < 300
                        ? {
                              options: {
                                  user: USER_OPTIONS,
                                  team: TEAM_OPTIONS,
                                  role: ROLE_OPTIONS,
                              },
                          }
                        : { message: 'refused' },
                ),
            );
        }

        if (method === 'GET' && path === pathOf(sharesRoute())) {
            const status = options.sharesStatus ?? 200;

            return Promise.resolve(
                jsonResponse(
                    status,
                    status < 300
                        ? { data: options.shares ?? [] }
                        : { message: 'refused' },
                ),
            );
        }

        if (method === 'POST' && path === pathOf(sharesRoute())) {
            return Promise.resolve(
                jsonResponse(options.createStatus ?? 201, {
                    data: share({ id: 'share-new' }),
                }),
            );
        }

        if (method === 'DELETE') {
            return Promise.resolve(
                jsonResponse(options.revokeStatus ?? 204, null),
            );
        }

        if (method === 'PUT' && path === pathOf(dashboardRoute())) {
            const status = options.tenantWideStatus ?? 200;

            return Promise.resolve(
                jsonResponse(
                    status,
                    status === 422
                        ? {
                              message: SERVER_ERROR_TEXT,
                              errors: { is_tenant_wide: [SERVER_ERROR_TEXT] },
                          }
                        : { data: { id: DASHBOARD_ID } },
                ),
            );
        }

        return Promise.resolve(jsonResponse(200, { data: null }));
    });

    vi.stubGlobal('fetch', mock);

    return mock;
}

async function mountCard(
    overrides: Partial<DashboardRow> = {},
): Promise<Wrapper> {
    const wrapper = mount(DashboardShareSheet, {
        props: { dashboard: dashboard(overrides), open: true },
        global: { stubs },
    });

    await flushPromises();

    return wrapper;
}

function granteeOptionValues(wrapper: Wrapper): string[] {
    return wrapper
        .get('[data-grantee-select]')
        .findAll('option')
        .map((option) => String(option.attributes('value')));
}

function granteeOptions(wrapper: Wrapper): SelectOption[] {
    return wrapper
        .findComponent<typeof Combobox>('.ui-combobox-wrapper')
        .props('options') as SelectOption[];
}

async function chooseGranteeType(
    wrapper: Wrapper,
    kind: string,
): Promise<void> {
    await wrapper.get('[data-grantee-type-select]').setValue(kind);
    await flushPromises();
}

beforeEach(() => {
    setUrlDefaults({ activeTeam: ACTIVE_TEAM });
    inertia.pageState.props.auth.authority = null;
    inertia.visit.mockReset();
    inertia.put.mockReset();
    inertia.post.mockReset();
    toastError.mockReset();
});

afterEach(() => {
    vi.unstubAllGlobals();
    setUrlDefaults({});
});

describe('DashboardShareSheet — when the server refuses', () => {
    it('says in German that the shares could not be loaded', async () => {
        shareFetch({ sharesStatus: 500 });

        const wrapper = await mountCard();

        expect(wrapper.text()).toContain(SHARES_LOAD_ERROR_MESSAGE);
        expect(wrapper.find('[data-share-create]').exists()).toBe(true);
        expect(toastError).toHaveBeenCalledTimes(1);
    });

    it('says in German that the recipients could not be loaded and offers none', async () => {
        shareFetch({ optionsStatus: 500 });

        const wrapper = await mountCard();

        expect(wrapper.text()).toContain(OPTIONS_LOAD_ERROR_MESSAGE);
        expect(granteeOptions(wrapper)).toEqual([]);
        expect(wrapper.find('[data-share-create]').exists()).toBe(true);
        expect(toastError).toHaveBeenCalledTimes(1);
    });

    it('keeps the list untouched when a new share is refused', async () => {
        const fetchMock = shareFetch({
            createStatus: 422,
            shares: [share({ id: 'share-7', grantee_name: 'Anna Albers' })],
        });
        const wrapper = await mountCard();

        expect(wrapper.findAll('[data-share-row]')).toHaveLength(1);

        await wrapper.get('[data-share-create]').trigger('click');
        await flushPromises();

        expect(callsTo(fetchMock, 'POST', sharesRoute())).toHaveLength(1);
        expect(wrapper.text()).toContain(SHARE_CREATE_ERROR_MESSAGE);
        expect(wrapper.findAll('[data-share-row]')).toHaveLength(1);
        expect(toastError).toHaveBeenCalledTimes(1);
    });

    it('leaves the row standing when the revocation is refused', async () => {
        shareFetch({
            revokeStatus: 500,
            shares: [share({ id: 'share-7', grantee_name: 'Anna Albers' })],
        });
        const wrapper = await mountCard();

        await wrapper
            .get('[data-share-row]')
            .get('[data-share-revoke]')
            .trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain(SHARE_REVOKE_ERROR_MESSAGE);
        expect(wrapper.findAll('[data-share-row]')).toHaveLength(1);
        expect(wrapper.get('[data-share-row]').text()).toContain('Anna Albers');
        expect(toastError).toHaveBeenCalledTimes(1);
    });
});

describe('DashboardShareSheet — picking a recipient', () => {
    it('offers the recipients the server sent and never a free text field for an id', async () => {
        const fetchMock = shareFetch();
        const wrapper = await mountCard();

        const offered = granteeOptionValues(wrapper);

        expect(wrapper.find('[data-share-create]').exists()).toBe(true);
        expect(offered.length).toBeGreaterThan(0);

        await wrapper.get('[data-grantee-select]').setValue(offered[1]);
        await wrapper.get('[data-share-create]').trigger('click');
        await flushPromises();

        const posts = callsTo(fetchMock, 'POST', sharesRoute());

        expect(posts).toHaveLength(1);

        const body = bodyOf(posts[0]);

        expect(Object.keys(body).sort()).toEqual([
            'can_edit',
            'grantee_id',
            'grantee_type',
        ]);
        expect(offered).toContain(String(body.grantee_id));
        expect(String(body.grantee_type)).not.toContain('App\\Models');
    });

    it('offers no public link, no token and nothing to copy', async () => {
        shareFetch();
        const wrapper = await mountCard();

        expect(wrapper.find('[data-share-create]').exists()).toBe(true);
        expect(wrapper.text()).not.toMatch(PUBLIC_ACCESS_WORDS);
        expect(wrapper.find('input[type="url"]').exists()).toBe(false);
    });

    it('swaps the whole recipient list when the kind of recipient changes', async () => {
        shareFetch();
        const wrapper = await mountCard();

        await chooseGranteeType(wrapper, 'user');

        expect(granteeOptionValues(wrapper)).toEqual(
            USER_OPTIONS.map((option) => option.value),
        );
        expect(
            granteeOptions(wrapper).every(
                (option) => option.avatar !== undefined,
            ),
        ).toBe(true);

        await chooseGranteeType(wrapper, 'team');

        expect(granteeOptionValues(wrapper)).toEqual(
            TEAM_OPTIONS.map((option) => option.value),
        );
        expect(
            granteeOptions(wrapper).some(
                (option) => option.avatar !== undefined,
            ),
        ).toBe(false);

        await chooseGranteeType(wrapper, 'role');

        expect(granteeOptionValues(wrapper)).toEqual(
            ROLE_OPTIONS.map((option) => option.value),
        );
        expect(
            granteeOptions(wrapper).some(
                (option) => option.avatar !== undefined,
            ),
        ).toBe(false);
    });

    it('sends can_edit as chosen and revokes through the endpoint of that share', async () => {
        const fetchMock = shareFetch({
            shares: [share({ id: 'share-7', grantee_name: 'Anna Albers' })],
        });
        const wrapper = await mountCard();

        await wrapper.get('[data-can-edit-toggle]').trigger('click');
        await wrapper.get('[data-share-create]').trigger('click');
        await flushPromises();

        expect(
            bodyOf(callsTo(fetchMock, 'POST', sharesRoute())[0]).can_edit,
        ).toBe(true);

        await wrapper
            .get('[data-share-row]')
            .get('[data-share-revoke]')
            .trigger('click');
        await flushPromises();

        expect(
            callsTo(fetchMock, 'DELETE', revokeRoute('share-7')),
        ).toHaveLength(1);
    });

    it('names a share whose kind the server could not resolve without leaking undefined', async () => {
        shareFetch({
            shares: [
                share({ id: 'share-user', grantee_name: 'Anna Albers' }),
                share({
                    id: 'share-broken',
                    grantee_type: null,
                    grantee_id: 'gone',
                    grantee_name: null,
                }),
            ],
        });
        const wrapper = await mountCard();

        const rows = wrapper.findAll('[data-share-row]');

        expect(rows).toHaveLength(2);
        expect(rows[0].text()).toContain('Anna Albers');
        expect(rows[1].text().trim().length).toBeGreaterThan(0);
        expect(rows[1].text()).not.toContain('undefined');
        expect(rows[1].text()).not.toContain('null');
        expect(rows[1].text()).not.toMatch(ENGLISH_WORDS);
    });
});

describe('DashboardShareSheet — the warning about foreign rights', () => {
    it('warns exactly when the dashboard carries a definer bound widget', async () => {
        shareFetch();

        const warned = await mountCard({ has_definer_widget: true });

        expect(warned.get('[data-definer-warning]').text()).toBe(
            DEFINER_SHARE_WARNING,
        );
        expect(warned.find('[data-share-create]').exists()).toBe(true);

        const quiet = await mountCard({ has_definer_widget: false });

        expect(quiet.find('[data-definer-warning]').exists()).toBe(false);
        expect(quiet.find('[data-share-create]').exists()).toBe(true);
    });
});

describe('DashboardShareSheet — a viewer who may not share', () => {
    it('offers nothing and asks the server for no options', async () => {
        const blockedFetch = shareFetch();
        const blocked = await mountCard({
            can_share: false,
            share_reason: 'not_owner',
            is_owner: false,
        });

        expect(blocked.find('[data-share-create]').exists()).toBe(false);
        expect(blocked.find('[data-grantee-select]').exists()).toBe(false);
        expect(callsTo(blockedFetch, 'GET', optionsRoute())).toHaveLength(0);

        blocked.unmount();
        vi.unstubAllGlobals();

        const allowedFetch = shareFetch();
        const allowed = await mountCard({ can_share: true });

        expect(allowed.find('[data-share-create]').exists()).toBe(true);
        expect(
            callsTo(allowedFetch, 'GET', optionsRoute()).length,
        ).toBeGreaterThan(0);
    });
});

describe('DashboardShareSheet — while it is still closed', () => {
    it('asks the server for nothing until the panel is opened', async () => {
        const fetchMock = shareFetch();

        const wrapper = mount(DashboardShareSheet, {
            props: { dashboard: dashboard(), open: false },
            global: { stubs },
        });

        await flushPromises();

        expect(callsTo(fetchMock, 'GET', sharesRoute())).toHaveLength(0);
        expect(callsTo(fetchMock, 'GET', optionsRoute())).toHaveLength(0);

        await wrapper.setProps({ open: true });
        await flushPromises();

        expect(callsTo(fetchMock, 'GET', sharesRoute()).length).toBeGreaterThan(
            0,
        );
        expect(
            callsTo(fetchMock, 'GET', optionsRoute()).length,
        ).toBeGreaterThan(0);
    });

    it('closes through the panel itself', async () => {
        shareFetch();

        const wrapper = await mountCard();

        wrapper.getComponent({ name: 'Sheet' }).vm.$emit('update:open', false);
        await nextTick();

        expect(wrapper.emitted('close')).toHaveLength(1);
    });
});

describe('DashboardShareSheet — making a dashboard visible tenant wide', () => {
    it('offers the switch to an escalated authority only', async () => {
        shareFetch();

        inertia.pageState.props.auth.authority = 'super_admin';

        const escalated = await mountCard();

        expect(escalated.find('[data-tenant-wide-toggle]').exists()).toBe(true);
        expect(escalated.find('[data-share-create]').exists()).toBe(true);

        escalated.unmount();
        inertia.pageState.props.auth.authority = null;

        const ordinary = await mountCard();

        expect(ordinary.find('[data-tenant-wide-toggle]').exists()).toBe(false);
        expect(ordinary.find('[data-share-create]').exists()).toBe(true);
    });

    it('persists the switch through one request and never through an Inertia visit', async () => {
        const fetchMock = shareFetch();

        inertia.pageState.props.auth.authority = 'super_admin';

        const wrapper = await mountCard({ is_tenant_wide: false });

        await wrapper.get('[data-tenant-wide-toggle]').trigger('click');
        await flushPromises();

        const puts = callsTo(fetchMock, 'PUT', dashboardRoute());

        expect(puts).toHaveLength(1);
        expect(bodyOf(puts[0])).toEqual({ is_tenant_wide: true });
        expect(inertia.put).not.toHaveBeenCalled();
        expect(inertia.visit).not.toHaveBeenCalled();
    });

    it('turns the switch back and shows the server message when the write is refused', async () => {
        shareFetch({ tenantWideStatus: 422 });

        inertia.pageState.props.auth.authority = 'super_admin';

        const wrapper = await mountCard({ is_tenant_wide: false });
        const toggle = () =>
            wrapper.findComponent<typeof Checkbox>('[data-tenant-wide-toggle]');

        await wrapper.get('[data-tenant-wide-toggle]').trigger('click');
        await nextTick();

        expect(toggle().props('modelValue')).toBe(true);

        await flushPromises();

        expect(toggle().props('modelValue')).toBe(false);
        expect(wrapper.get('[data-tenant-wide-error]').text()).toContain(
            SERVER_ERROR_TEXT,
        );
    });
});
