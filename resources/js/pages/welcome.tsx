import { Head, Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { buttonVariants } from '@/components/ui/button';
import { dashboard, home, login } from '@/routes';

const steps = [
    {
        number: '01',
        title: 'Verified facts',
        body: 'Jobs, projects and achievements are written down once and approved before anything uses them.',
    },
    {
        number: '02',
        title: 'Tailored resumes',
        body: 'Each resume picks, orders and rewords bullets from the library, so every version tells the truth.',
    },
    {
        number: '03',
        title: 'Copy-ready answers',
        body: 'Application answers cite the facts behind them, and anything unverified is flagged for review.',
    },
] as const;

/**
 * The front door. Atlas has no public signup (accounts are created
 * by the candidate), so this only says what the app is and leads to log in.
 */
export default function Welcome() {
    const { auth, name } = usePage().props;

    return (
        <>
            <Head title="Welcome" />
            <div className="flex min-h-svh flex-col bg-bg-page">
                <header className="mx-auto flex w-full max-w-7xl items-center justify-between px-4 py-6 md:px-10">
                    <Link
                        href={home()}
                        className="flex items-center gap-3 rounded-kraken-md focus-ring"
                    >
                        <span className="flex size-9 items-center justify-center rounded-kraken-md bg-surface-accent text-text-on-accent">
                            <AppLogoIcon className="size-6 fill-current" />
                        </span>
                        <span className="pt-0.5 type-submit text-text-primary">
                            {name}
                        </span>
                    </Link>
                    <nav>
                        {auth.user ? (
                            <Link
                                href={dashboard()}
                                className={buttonVariants({
                                    variant: 'outline',
                                })}
                            >
                                Dashboard
                            </Link>
                        ) : (
                            <Link
                                href={login()}
                                className={buttonVariants({
                                    variant: 'outline',
                                })}
                            >
                                Log in
                            </Link>
                        )}
                    </nav>
                </header>

                <main className="mx-auto flex w-full max-w-7xl flex-1 flex-col justify-center gap-16 px-4 py-12 md:gap-20 md:px-10 md:py-20">
                    <section className="flex max-w-4xl flex-col items-start gap-8">
                        <p className="type-nav-link text-text-muted uppercase">
                            Resumes and applications, evidence first
                        </p>
                        <h1 className="type-display-xl text-text-primary">
                            Every answer backed by{' '}
                            <span className="text-text-accent">facts</span>
                        </h1>
                        <p className="max-w-xl type-body-l text-text-secondary">
                            Build one verified library of your career, then turn
                            it into tailored resumes and application answers in
                            minutes, with nothing invented along the way.
                        </p>
                        <Link
                            href={auth.user ? dashboard() : login()}
                            className={buttonVariants({ size: 'lg' })}
                        >
                            {auth.user ? 'Open your dashboard' : 'Log in'}
                        </Link>
                    </section>

                    <ol className="grid gap-3 md:grid-cols-3 md:gap-6">
                        {steps.map((step) => (
                            <li
                                key={step.number}
                                className="flex flex-col gap-4 rounded-field bg-surface-panel p-6 md:p-8"
                            >
                                <span className="type-display-l text-text-accent">
                                    {step.number}
                                </span>
                                <h2 className="type-heading-3 text-text-primary">
                                    {step.title}
                                </h2>
                                <p className="type-body text-text-muted">
                                    {step.body}
                                </p>
                            </li>
                        ))}
                    </ol>
                </main>

                <footer className="mx-auto w-full max-w-7xl border-t border-border-subtle px-4 py-6 md:px-10">
                    <p className="type-footer-link text-text-subtle">
                        Private workspace. Accounts are created by invitation.
                    </p>
                </footer>
            </div>
        </>
    );
}
