import { Link } from '@inertiajs/react';
import AppearanceToggleTab from '@/components/appearance-tabs';
import { styleguide } from '@/routes';
import { components } from '@/routes/styleguide';

const navLinkClasses =
    'type-nav-link text-text-muted uppercase focus-ring transition-colors duration-fast ease-out hover:text-text-primary';

/**
 * Header for the local-only styleguide pages, with the appearance switch so
 * both themes can be checked side by side.
 */
export function StyleguideHeader({
    title,
    description,
}: {
    title: string;
    description: string;
}) {
    return (
        <header className="flex flex-col gap-6">
            <nav className="flex flex-wrap items-center justify-between gap-4">
                <div className="flex gap-8">
                    <Link href={styleguide()} className={navLinkClasses}>
                        Tokens
                    </Link>
                    <Link href={components()} className={navLinkClasses}>
                        Components
                    </Link>
                </div>
                <AppearanceToggleTab />
            </nav>
            <div className="flex flex-col gap-3">
                <h1 className="type-display-l text-text-primary">
                    Career Engine{' '}
                    <span className="text-text-accent">{title}</span>
                </h1>
                <p className="type-body-l text-text-secondary">{description}</p>
            </div>
        </header>
    );
}

export function StyleguideSection({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="flex flex-col gap-8">
            <h2 className="type-heading-3 text-text-accent">{title}</h2>
            {children}
        </section>
    );
}
