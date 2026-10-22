import { createContext, useContext } from 'react';

/**
 * What differs between the editors of a resume variant and of a tailored application resume,
 * read deep in the tree (bullet rows, job cards).
 */
type EditorOptions = {
    /** Endpoint for "Apply everywhere" on a reworded bullet, or null to hide the action. */
    applyBulletUrl: ((claimUlid: string) => string) | null;
    /** Endpoint for "Save as a new bullet point" on a reworded bullet, or null to hide it. */
    newBulletUrl: ((claimUlid: string) => string) | null;
    /** Display titles ("Shown as") are the candidate's call. */
    canEditDisplayTitles: boolean;
};

const EditorOptionsContext = createContext<EditorOptions>({
    applyBulletUrl: null,
    newBulletUrl: null,
    canEditDisplayTitles: false,
});

export const ResumeEditorOptions = EditorOptionsContext;

export function useResumeEditorOptions(): EditorOptions {
    return useContext(EditorOptionsContext);
}
