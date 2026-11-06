export interface TeamTreeNode {
    id: string;
    name: string;
    slug: string;
    parentTeamId: string | null;
    depth: number;
    descendantTeamIds: string[];
    can_delete: boolean;
    delete_reason: string | null;
}

export interface TeamSummary {
    id: string;
    slug?: string;
    name: string;
}
