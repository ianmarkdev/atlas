import { Link } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

type Props = ComponentProps<typeof Link>;

export default function TextLink({
    className = '',
    children,
    ...props
}: Props) {
    return (
        <Link
            className={cn(
                'text-text-accent underline decoration-border-accent-subtle underline-offset-4 focus-ring transition-colors duration-fast ease-out hover:decoration-border-accent',
                className,
            )}
            {...props}
        >
            {children}
        </Link>
    );
}
