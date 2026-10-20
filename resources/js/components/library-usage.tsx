import { createContext, useContext } from 'react';

/**
 * Where each bullet point is used: resume names and the number of answers relying on it,
 * keyed by claim ULID. Provided by the Library page; elsewhere nothing is shown.
 */
export const LibraryUsageContext = createContext<{
    resumes: Record<string, string[]>;
    answers: Record<string, number>;
} | null>(null);

export function ClaimUsage({ ulid }: { ulid: string }) {
    const usage = useContext(LibraryUsageContext);

    if (usage === null) {
        return null;
    }

    const parts = [usageLabel(usage.resumes[ulid])];
    const answers = usage.answers[ulid] ?? 0;

    if (answers > 0) {
        parts.push(`backs ${answers} ${answers === 1 ? 'answer' : 'answers'}`);
    }

    return <span>{parts.join(' · ')}</span>;
}

export function usageLabel(resumes: string[] | undefined): string {
    return resumes && resumes.length > 0
        ? `Used in: ${resumes.join(', ')}`
        : 'Not on any resume yet';
}
