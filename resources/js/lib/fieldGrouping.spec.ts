import { describe, expect, it } from 'vitest';
import {
    buildFieldGridRows,
    buildFieldSections,
    headerIdOf,
} from '@/lib/fieldGrouping';
import type { FieldGridRow, FieldGroupRow } from '@/types/fieldGroups';
import {
    isFieldGroupHeaderRow,
    UNGROUPED_HEADER_ID,
} from '@/types/fieldGroups';
import type { FieldDefinition } from '@/types/fields';
import type { ObjectTypeFieldRow } from '@/types/formulas';

function field(
    key: string,
    fieldGroupId: string | null = null,
): ObjectTypeFieldRow {
    return {
        id: `fd-${key}`,
        field_group_id: fieldGroupId,
        key,
        field_type: 'text_short',
        label: key,
        description: null,
        is_required: false,
        is_unique: false,
        is_searchable: false,
        is_translatable: false,
        is_encrypted: false,
        is_sortable: false,
        is_filterable: false,
        is_default_column: false,
        is_card_field: false,
        is_reserved: false,
        is_type_changeable: true,
        list_position: null,
        config: null,
        validation_rules: null,
        default_value: null,
    };
}

const address: FieldGroupRow = {
    id: 'fg-address',
    key: 'address',
    label: 'Adresse',
    description: null,
    position: 1,
};

const contact: FieldGroupRow = {
    id: 'fg-contact',
    key: 'contact',
    label: 'Kontakt',
    description: null,
    position: 2,
};

function labels(rows: FieldGridRow[]): string[] {
    return rows.map((row) =>
        isFieldGroupHeaderRow(row) ? `# ${row.label}` : row.key,
    );
}

describe('buildFieldGridRows', () => {
    it('leaves the field list untouched when no group exists', () => {
        const fields = [field('name'), field('note')];

        expect(buildFieldGridRows(fields, [])).toEqual(fields);
    });

    it('puts the ungrouped fields first and then every group in position order', () => {
        const rows = buildFieldGridRows(
            [
                field('name'),
                field('street', address.id),
                field('email', contact.id),
                field('city', address.id),
            ],
            [contact, address],
        );

        expect(labels(rows)).toEqual([
            '# Ohne Gruppe',
            'name',
            '# Adresse',
            'street',
            'city',
            '# Kontakt',
            'email',
        ]);
    });

    it('omits the ungrouped header when every field carries a group', () => {
        const rows = buildFieldGridRows(
            [field('street', address.id)],
            [address],
        );

        expect(labels(rows)).toEqual(['# Adresse', 'street']);
    });

    it('keeps an empty group visible so fields can be moved into it', () => {
        const rows = buildFieldGridRows([field('name')], [address]);
        const head = rows[2];

        expect(labels(rows)).toEqual(['# Ohne Gruppe', 'name', '# Adresse']);
        expect(isFieldGroupHeaderRow(head) && head.count).toBe(0);
    });

    it('counts the members of every group on its header', () => {
        const rows = buildFieldGridRows(
            [field('street', address.id), field('city', address.id)],
            [address],
        );
        const head = rows[0];

        expect(isFieldGroupHeaderRow(head) && head.count).toBe(2);
    });

    it('hides the members of a collapsed group but keeps its header', () => {
        const rows = buildFieldGridRows(
            [field('name'), field('street', address.id)],
            [address],
            new Set([headerIdOf(address.id)]),
        );

        expect(labels(rows)).toEqual(['# Ohne Gruppe', 'name', '# Adresse']);
        const head = rows[2];
        expect(isFieldGroupHeaderRow(head) && head.collapsed).toBe(true);
    });

    it('collapses the ungrouped section by its own header id', () => {
        const rows = buildFieldGridRows(
            [field('name'), field('street', address.id)],
            [address],
            new Set([UNGROUPED_HEADER_ID]),
        );

        expect(labels(rows)).toEqual(['# Ohne Gruppe', '# Adresse', 'street']);
    });

    it('gives every row a unique id so the grid can track them', () => {
        const rows = buildFieldGridRows(
            [field('name'), field('street', address.id)],
            [address],
        );

        expect(new Set(rows.map((row) => row.id)).size).toBe(rows.length);
    });
});

function definition(
    key: string,
    fieldGroupId: string | null = null,
): FieldDefinition {
    return {
        key,
        field_group_id: fieldGroupId,
        field_type: 'text_short',
        label: key,
        description: null,
        is_required: false,
    };
}

describe('buildFieldSections', () => {
    it('carries the description of every group into its section', () => {
        const described = { ...address, description: 'Die Anschrift.' };
        const sections = buildFieldSections(
            [definition('name'), definition('street', address.id)],
            [described],
        );

        expect(sections.map((section) => section.description)).toEqual([
            null,
            'Die Anschrift.',
        ]);
    });

    it('titles the ungrouped section "Daten" and leaves it without a description', () => {
        const [section] = buildFieldSections([definition('name')], []);

        expect(section.label).toBe('Daten');
        expect(section.description).toBeNull();
    });

    it('titles the ungrouped section "Daten" next to the group sections', () => {
        const sections = buildFieldSections(
            [definition('name'), definition('street', address.id)],
            [address],
        );

        expect(sections.map((section) => section.label)).toEqual([
            'Daten',
            'Adresse',
        ]);
    });
});
