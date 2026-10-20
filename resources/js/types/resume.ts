import type {
    ContactLink,
    Credential,
    Employment,
    Language,
    Project,
    Skill,
} from './knowledge';

/** A bullet always points at a claim; `text` is a rewording for this resume only (null = shared wording). */
export type ResumeBullet = {
    claim: string;
    text: string | null;
};

export type ResumeProjectItem = {
    project: string;
    description: string | null;
    bullets: ResumeBullet[];
};

export type ResumeJob = {
    employment: string;
    display_title: string | null;
    description: string | null;
    bullets: ResumeBullet[];
    projects: ResumeProjectItem[];
};

/** A labelled group of skills, e.g. "Backend: PHP, Laravel". */
export type ResumeSkillGroup = {
    label: string | null;
    items: string[];
};

export type ResumeCredentialItem = {
    credential: string;
};

/** Mirrors App\Domain\Resumes\ResumeDocumentValidator (schema_version 1). */
export type ResumeDocument = {
    schema_version: 1;
    title: string | null;
    summary: string | null;
    experience: ResumeJob[];
    projects: ResumeProjectItem[];
    skills: ResumeSkillGroup[];
    education: ResumeCredentialItem[];
    certifications: ResumeCredentialItem[];
    languages: string[];
    links: string[];
};

export type ResumeLibrary = {
    employments: Employment[];
    standaloneProjects: Project[];
    education: Credential[];
    certifications: Credential[];
    skills: Skill[];
    languages: Language[];
    links: ContactLink[];
};

/** Names of the other resumes that include each library item, keyed by ULID. */
export type ResumeUsage = {
    employments: Record<string, string[]>;
    projects: Record<string, string[]>;
    claims: Record<string, string[]>;
    credentials: Record<string, string[]>;
    skills: Record<string, string[]>;
};

export type Contact = {
    display_name: string;
    email: string | null;
    phone: string | null;
    location: string | null;
};
