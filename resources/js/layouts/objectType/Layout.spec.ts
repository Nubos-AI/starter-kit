import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import ObjectTypesController from '@/actions/App/Http/Controllers/Engine/ObjectTypesController';
import Layout from '@/layouts/objectType/Layout.vue';
import { setUrlDefaults } from '@/wayfinder';

setUrlDefaults({ activeTeam: 'nubos' });

interface MockPage {
    url: string;
    props: {
        auth: { user: null; can: Record<string, boolean>; authority: null };
        objectType: Record<string, unknown> | null;
        mode?: 'create' | 'edit';
    };
}

const { mocks } = vi.hoisted(() => ({
    mocks: {} as { page: MockPage },
}));

vi.mock('@inertiajs/vue3', () => {
    mocks.page = reactive<MockPage>({
        url: '/engine/object-types/departments/edit',
        props: {
            auth: { user: null, can: {}, authority: null },
            objectType: null,
        },
    });

    return {
        Link: { props: ['href'], template: '<a :href="href.url"><slot /></a>' },
        usePage: () => mocks.page,
    };
});

const SECTION_URLS = [
    ObjectTypesController.edit({ objectType: 'departments' }).url,
    ObjectTypesController.fields({ objectType: 'departments' }).url,
    ObjectTypesController.agingRules({ objectType: 'departments' }).url,
    ObjectTypesController.mergeRules({ objectType: 'departments' }).url,
    ObjectTypesController.permissions({ objectType: 'departments' }).url,
];

function objectType(
    overrides: Record<string, unknown> = {},
): Record<string, unknown> {
    return {
        id: 'ot-1',
        key: 'departments',
        slug: 'departments',
        name: 'Departments',
        business_key_prefix: 'DP',
        record_number_format: '##########',
        business_key_locked: false,
        is_system: false,
        storage_strategy: 'generic',
        hierarchy_relationship_type_id: null,
        ...overrides,
    };
}

function mountLayout() {
    return mount(Layout, { slots: { default: '<p>Abschnitt</p>' } });
}

beforeEach(() => {
    mocks.page.url = SECTION_URLS[0];
    mocks.page.props.objectType = objectType();
    mocks.page.props.mode = undefined;
});

describe('objectType/Layout', () => {
    it('lists every section of the object type in a fixed order', () => {
        const links = mountLayout().findAll('[data-object-type-sections] a');

        expect(links.map((link) => link.text())).toEqual([
            'Details',
            'Felder',
            'Aging-Regeln',
            'Merge-Regeln',
            'Rechte',
        ]);
        expect(links.map((link) => link.attributes('href'))).toEqual(
            SECTION_URLS,
        );
    });

    it('marks exactly the section the current url points at', () => {
        mocks.page.url = SECTION_URLS[1];

        const active = mountLayout().findAll('[data-section-active]');

        expect(active).toHaveLength(1);
        expect(active[0].text()).toBe('Felder');
    });

    it('keeps the details entry unmarked while a sub-section is open', () => {
        mocks.page.url = SECTION_URLS[2];

        const wrapper = mountLayout();

        expect(wrapper.findAll('[data-section-active]')).toHaveLength(1);
        expect(wrapper.find('[data-section-active]').text()).toBe(
            'Aging-Regeln',
        );
    });

    it('renders the section content it wraps', () => {
        expect(mountLayout().text()).toContain('Abschnitt');
    });

    it('names the object type and stays quiet about the system flag', () => {
        const wrapper = mountLayout();

        expect(wrapper.text()).toContain('Departments');
        expect(wrapper.text()).not.toContain(
            'Systemobjekttypen sind schreibgeschützt',
        );
    });

    it('marks a system object type as read-only', () => {
        mocks.page.props.objectType = objectType({ is_system: true });

        const wrapper = mountLayout();

        expect(wrapper.text()).toContain('System');
        expect(wrapper.text()).toContain(
            'Systemobjekttypen sind schreibgeschützt',
        );
    });

    it('names the sections of a not yet saved object type in the same order', () => {
        mocks.page.url = ObjectTypesController.create().url;
        mocks.page.props.objectType = null;
        mocks.page.props.mode = 'create';

        const sections = mountLayout().findAll(
            '[data-object-type-sections] [data-section]',
        );

        expect(sections.map((section) => section.text())).toEqual([
            'Details',
            'Felder',
            'Aging-Regeln',
            'Merge-Regeln',
            'Rechte',
        ]);
    });

    it('leaves only the details section reachable while creating', () => {
        mocks.page.url = ObjectTypesController.create().url;
        mocks.page.props.objectType = null;
        mocks.page.props.mode = 'create';

        const wrapper = mountLayout();

        expect(
            wrapper
                .findAll('[data-object-type-sections] a')
                .map((link) => link.text()),
        ).toEqual(['Details']);
        expect(
            wrapper
                .findAll('[data-object-type-sections] [data-section-disabled]')
                .map((section) => section.text()),
        ).toEqual(['Felder', 'Aging-Regeln', 'Merge-Regeln', 'Rechte']);
        expect(wrapper.find('[data-section-active]').text()).toBe('Details');
    });

    it('announces the new object type instead of a name while creating', () => {
        mocks.page.url = ObjectTypesController.create().url;
        mocks.page.props.objectType = null;
        mocks.page.props.mode = 'create';

        expect(mountLayout().text()).toContain('Neuer Objekttyp');
    });

    it('renders the content alone when no object type reaches the layout', () => {
        mocks.page.props.objectType = null;

        const wrapper = mountLayout();

        expect(wrapper.find('[data-object-type-sections]').exists()).toBe(
            false,
        );
        expect(wrapper.text()).toContain('Abschnitt');
    });
});
