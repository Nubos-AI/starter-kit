import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import SourceLinkList from '@/components/engine/links/SourceLinkList.vue';
import { linksBesidesRecord, readSourceLinks } from '@/lib/sourceLinks';

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

const recordLink = {
    key: 'record',
    id: 'record-1',
    label: 'C-1',
    href: '/nubos/records/record-1',
};
const mailLink = {
    key: 'email_message',
    id: 'mail-1',
    label: 'E-Mail: Angebot',
    href: '/nubos/mails/mail-1',
};

describe('SourceLinkList', () => {
    it('renders one link per target with its own href', () => {
        const wrapper = mount(SourceLinkList, {
            props: { links: [recordLink, mailLink] },
        });

        const links = wrapper.findAll('[data-source-link]');

        expect(links.map((link) => link.attributes('href'))).toEqual([
            '/nubos/records/record-1',
            '/nubos/mails/mail-1',
        ]);
        expect(links.map((link) => link.text())).toEqual([
            'C-1',
            'E-Mail: Angebot',
        ]);
    });

    it('renders nothing without a target', () => {
        const wrapper = mount(SourceLinkList, { props: { links: [] } });

        expect(wrapper.find('[data-source-links]').exists()).toBe(false);
    });
});

describe('sourceLinks helpers', () => {
    it('keeps only complete links from a server payload', () => {
        expect(
            readSourceLinks([
                mailLink,
                { key: 'record', id: 'x', label: 'C-9', href: '' },
                { key: 'record' },
                null,
            ]),
        ).toEqual([mailLink]);
        expect(readSourceLinks(undefined)).toEqual([]);
    });

    it('drops only the link back to the record that is already open', () => {
        expect(
            linksBesidesRecord(
                [
                    recordLink,
                    { ...recordLink, id: 'record-2', href: '/r/2' },
                    mailLink,
                ],
                'record-1',
            ),
        ).toEqual([{ ...recordLink, id: 'record-2', href: '/r/2' }, mailLink]);
    });
});
