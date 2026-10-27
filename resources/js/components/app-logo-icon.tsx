import type { SVGAttributes } from 'react';

/**
 * Product mark: a globe with an equator and a meridian, for Atlas.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg">
            <path
                fillRule="evenodd"
                clipRule="evenodd"
                d="M20 4a16 16 0 1 1 0 32a16 16 0 1 1 0-32Zm0 3a13 13 0 1 0 0 26a13 13 0 1 0 0-26Z"
            />
            <path
                fillRule="evenodd"
                clipRule="evenodd"
                d="M20 4a7 16 0 1 1 0 32a7 16 0 1 1 0-32Zm0 3a4.5 13 0 1 0 0 26a4.5 13 0 1 0 0-26Z"
            />
            <path d="M5 18.5h30v3H5z" />
        </svg>
    );
}
