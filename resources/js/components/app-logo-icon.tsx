import type { SVGAttributes } from 'react';

/**
 * Product mark: a resume page with a folded corner and text lines.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg">
            <path
                fillRule="evenodd"
                clipRule="evenodd"
                d="M10 3h15l9 9v23a2 2 0 0 1-2 2H10a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm14 2.5V13h7.5L24 5.5ZM13 18h14v3H13v-3Zm0 6h14v3H13v-3Zm0 6h9v3h-9v-3Z"
            />
        </svg>
    );
}
