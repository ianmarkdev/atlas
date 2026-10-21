import { Head } from '@inertiajs/react';
import { StyleguideHeader, StyleguideSection } from '@/components/styleguide';

// Local-only reference page for the Kraken tokens, ported from the Kraken
// site's styleguide. Class names are written out in full so Tailwind can
// see them.
const swatches = [
    ['color/bg/nav', 'bg-bg-nav'],
    ['color/bg/page', 'bg-bg-page'],
    ['color/bg/tint', 'bg-bg-tint'],
    ['color/surface/modal', 'bg-surface-modal'],
    ['color/surface/panel', 'bg-surface-panel'],
    ['color/surface/tile', 'bg-surface-tile'],
    ['color/surface/field', 'bg-surface-field'],
    ['color/surface/input-subtle', 'bg-surface-input-subtle'],
    ['color/surface/card', 'bg-surface-card'],
    ['color/surface/toggle', 'bg-surface-toggle'],
    ['color/surface/accent', 'bg-surface-accent'],
    ['color/surface/accent-hover', 'bg-surface-accent-hover'],
    ['color/surface/accent-pressed', 'bg-surface-accent-pressed'],
    ['color/surface/accent-subtle', 'bg-surface-accent-subtle'],
    ['color/surface/error-subtle', 'bg-surface-error-subtle'],
    ['color/overlay/scrim', 'bg-overlay-scrim'],
    ['color/text/primary', 'bg-text-primary'],
    ['color/text/secondary', 'bg-text-secondary'],
    ['color/text/tertiary', 'bg-text-tertiary'],
    ['color/text/muted', 'bg-text-muted'],
    ['color/text/label', 'bg-text-label'],
    ['color/text/subtle', 'bg-text-subtle'],
    ['color/text/placeholder', 'bg-text-placeholder'],
    ['color/text/accent', 'bg-text-accent'],
    ['color/text/on-accent', 'bg-text-on-accent'],
    ['color/text/error', 'bg-text-error'],
    ['color/border/subtle', 'bg-border-subtle'],
    ['color/border/field', 'bg-border-field'],
    ['color/border/input', 'bg-border-input'],
    ['color/border/toggle', 'bg-border-toggle'],
    ['color/border/accent', 'bg-border-accent'],
    ['color/border/accent-subtle', 'bg-border-accent-subtle'],
    ['color/border/focus', 'bg-border-focus'],
    ['color/border/focus-inverse', 'bg-border-focus-inverse'],
    ['color/border/error', 'bg-border-error'],
] as const;

const textStyles = [
    ['Kraken/Display XL', 'type-display-xl'],
    ['Kraken/Display L', 'type-display-l'],
    ['Kraken/Form Title', 'type-form-title'],
    ['Kraken/Heading 2', 'type-heading-2'],
    ['Kraken/Heading 3', 'type-heading-3'],
    ['Card Title (app)', 'type-card-title'],
    ['Kraken/Submit', 'type-submit'],
    ['Kraken/Upload', 'type-upload'],
    ['Kraken/Button', 'type-button'],
    ['Kraken/Pill', 'type-pill'],
    ['Kraken/Body L', 'type-body-l'],
    ['Kraken/Body', 'type-body'],
    ['Kraken/Input', 'type-input'],
    ['Kraken/Footer Link', 'type-footer-link'],
    ['Kraken/Caption', 'type-caption'],
    ['Kraken/Toggle', 'type-toggle'],
    ['Kraken/Nav Link', 'type-nav-link'],
    ['Kraken/Label', 'type-label'],
    ['Kraken/Footer Heading', 'type-footer-heading'],
] as const;

const radii = [
    ['radius/none', 'rounded-none'],
    ['radius/sm', 'rounded-kraken-sm'],
    ['radius/md', 'rounded-kraken-md'],
    ['radius/field', 'rounded-field'],
    ['radius/panel', 'rounded-panel'],
    ['radius/modal', 'rounded-modal'],
    ['radius/pill', 'rounded-pill'],
] as const;

const spacing = [
    ['space/2', 'w-0.5'],
    ['space/4', 'w-1'],
    ['space/8', 'w-2'],
    ['space/12', 'w-3'],
    ['space/14', 'w-3.5'],
    ['space/16', 'w-4'],
    ['space/20', 'w-5'],
    ['space/24', 'w-6'],
    ['space/28', 'w-7'],
    ['space/32', 'w-8'],
    ['space/40', 'w-10'],
    ['space/56', 'w-14'],
    ['space/80', 'w-20'],
    ['space/140', 'w-35'],
] as const;

export default function Styleguide() {
    return (
        <>
            <Head title="Styleguide" />
            <main className="mx-auto flex max-w-7xl flex-col gap-20 px-4 py-12 md:px-10 md:py-20">
                <StyleguideHeader
                    title="Tokens"
                    description="The Kraken tokens and text styles as built in this app. Dark mode is the Kraken design; light mode is ours."
                />

                <StyleguideSection title="Semantic colour tokens">
                    <ul className="grid grid-cols-2 gap-x-6 gap-y-8 sm:grid-cols-3 lg:grid-cols-5">
                        {swatches.map(([token, className]) => (
                            <li key={token} className="flex flex-col gap-2">
                                <div
                                    className={`h-18 rounded-kraken-md border border-border-subtle ${className}`}
                                />
                                <span className="type-caption text-text-secondary">
                                    {token}
                                </span>
                            </li>
                        ))}
                    </ul>
                </StyleguideSection>

                <StyleguideSection title="Text styles">
                    <ul className="flex flex-col gap-6">
                        {textStyles.map(([style, className]) => (
                            <li
                                key={style}
                                className="flex flex-col gap-2 border-b border-border-subtle pb-6"
                            >
                                <span className="type-caption text-text-muted">
                                    {style} · {className}
                                </span>
                                <span
                                    className={`${className} text-text-primary`}
                                >
                                    Every resume starts with the facts
                                </span>
                            </li>
                        ))}
                    </ul>
                </StyleguideSection>

                <StyleguideSection title="Radius">
                    <ul className="flex flex-wrap gap-6">
                        {radii.map(([token, className]) => (
                            <li key={token} className="flex flex-col gap-2">
                                <div
                                    className={`h-30 w-45 border border-border-accent-subtle bg-surface-card ${className}`}
                                />
                                <span className="type-caption text-text-secondary">
                                    {token} · {className}
                                </span>
                            </li>
                        ))}
                    </ul>
                </StyleguideSection>

                <StyleguideSection title="Spacing">
                    <ul className="flex flex-col gap-3">
                        {spacing.map(([token, className]) => (
                            <li key={token} className="flex items-center gap-6">
                                <span className="w-40 type-caption text-text-secondary">
                                    {token} · {className}
                                </span>
                                <div
                                    className={`h-4 bg-surface-accent ${className}`}
                                />
                            </li>
                        ))}
                    </ul>
                </StyleguideSection>

                <StyleguideSection title="Motion and focus">
                    <div className="flex flex-wrap items-center gap-6">
                        <button
                            type="button"
                            className="cursor-pointer rounded-pill bg-surface-accent px-8 py-3.5 type-button text-text-on-accent focus-ring transition-colors duration-fast ease-out hover:bg-surface-accent-hover active:bg-surface-accent-pressed"
                        >
                            200ms · duration-fast
                        </button>
                        <button
                            type="button"
                            className="cursor-pointer rounded-pill border border-border-accent-subtle px-8 py-3.5 type-button text-text-accent focus-ring transition-colors duration-base ease-out hover:bg-surface-accent-subtle"
                        >
                            300ms · duration-base
                        </button>
                        <button
                            type="button"
                            className="cursor-pointer rounded-pill border border-border-subtle px-8 py-3.5 type-button text-text-primary focus-ring transition-[padding] duration-slow ease-tile hover:px-14"
                        >
                            600ms · duration-slow
                        </button>
                    </div>
                </StyleguideSection>
            </main>
        </>
    );
}
