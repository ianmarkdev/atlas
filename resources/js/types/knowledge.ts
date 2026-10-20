export type KnowledgeStatus = 'verified' | 'proposed' | 'archived';

export type KnowledgeAbilities = {
    update: boolean;
    verify: boolean;
    archive: boolean;
    restore: boolean;
};

export type ClaimStatus = 'verified' | 'proposed' | 'rejected' | 'archived';

export type Claim = {
    ulid: string;
    statement: string;
    kind: string;
    status: ClaimStatus;
    revision_no: number | null;
    lock_version: number;
    can: KnowledgeAbilities & { reject: boolean };
};

export type Project = {
    ulid: string;
    employment: string | null;
    name: string;
    client_name: string | null;
    role: string | null;
    start_date: string | null;
    end_date: string | null;
    summary: string | null;
    url: string | null;
    status: KnowledgeStatus;
    lock_version: number;
    claims?: Claim[];
    can: KnowledgeAbilities;
};

export type Employment = {
    ulid: string;
    employer_name: string;
    title: string;
    employment_type: string | null;
    location: string | null;
    start_date: string;
    end_date: string | null;
    summary: string | null;
    status: KnowledgeStatus;
    lock_version: number;
    projects?: Project[];
    claims?: Claim[];
    can: KnowledgeAbilities;
};

export type SelectOption = {
    value: string;
    label: string;
};
