export type KnowledgeStatus = 'verified' | 'proposed' | 'archived';

export type KnowledgeAbilities = {
    update: boolean;
    /** A VA may suggest a change to a verified item; the candidate approves it. */
    propose: boolean;
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

export type Credential = {
    ulid: string;
    type: 'education' | 'certification';
    institution: string;
    name: string;
    field_of_study: string | null;
    start_date: string | null;
    end_date: string | null;
    expires_on: string | null;
    credential_url: string | null;
    summary: string | null;
    status: KnowledgeStatus;
    lock_version: number;
    can: KnowledgeAbilities;
};

export type Skill = {
    ulid: string;
    name: string;
};

export type Language = {
    ulid: string;
    language: string;
    proficiency: string;
};

export type ContactLink = {
    ulid: string;
    kind: string;
    label: string;
    url: string;
};

export type Answer = {
    ulid: string;
    question: string;
    body: string;
    category: string;
    claims: string[];
    status: 'approved' | 'draft' | 'archived';
    revision_no: number | null;
    lock_version: number;
    updated_at: string | null;
    can: {
        update: boolean;
        propose: boolean;
        approve: boolean;
        archive: boolean;
        restore: boolean;
    };
};

export type TextRevision = {
    revision_no: number;
    text: string;
    change_reason: string | null;
    author: string | null;
    from_suggestion: boolean;
    created_at: string;
};

export type EvidenceGroup = {
    label: string;
    claims: { ulid: string; statement: string }[];
};

export type ProposalStatus =
    | 'pending'
    | 'approved'
    | 'rejected'
    | 'superseded'
    | 'conflict';

export type DiffPart = { op: 'equal' | 'insert' | 'delete'; text: string };

export type KnowledgeProposal = {
    ulid: string;
    type:
        | 'revise_claim'
        | 'revise_record'
        | 'revise_library_answer'
        | 'revise_resume_variant'
        | 'create_claim'
        | 'create_library_answer'
        | 'create_writing_preference';
    status: ProposalStatus;
    origin: 'manual' | 'feedback' | 'achievement_suggestion' | 'clarification';
    suggestion: {
        question: string | null;
        current: string;
        draft: string;
    } | null;
    /** The edited answer a feedback suggestion was learned from. */
    learned_from: {
        application: string;
        application_ulid: string;
        question: string;
    } | null;
    target_type:
        | 'claim'
        | 'employment'
        | 'project'
        | 'credential'
        | 'answer_library_entry'
        | 'writing_preference'
        | 'resume_variant';
    target_label: string;
    changes: {
        label: string;
        from: string | null;
        to: string | null;
        /** The wording the suggestion was based on, when the item changed since. */
        base: string | null;
        diff: DiffPart[] | null;
    }[];
    /** What may be reworded before approving, with the suggested value. */
    editable: {
        field: 'statement' | 'body' | 'rule';
        value: string;
        subject: string | null;
    } | null;
    personal: boolean;
    rationale: string | null;
    proposed_by: string | null;
    created_at: string | null;
    decided_by: string | null;
    decided_at: string | null;
    decision_note: string | null;
    can: { decide: boolean };
};

export type SelectOption = {
    value: string;
    label: string;
};
