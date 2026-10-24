import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { createPortal } from 'react-dom';

export const HEADER_ACTIONS_ID = 'app-header-actions';

/**
 * Renders its children at the right of the top bar (AppSidebarHeader), for the current page only.
 */
export function HeaderActions({ children }: { children: ReactNode }) {
    const [target, setTarget] = useState<HTMLElement | null>(null);

    useEffect(() => {
        setTarget(document.getElementById(HEADER_ACTIONS_ID));
    }, []);

    return target ? createPortal(children, target) : null;
}
