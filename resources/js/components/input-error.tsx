import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

export default function InputError({
    message,
    className = '',
    ...props
}: HTMLAttributes<HTMLParagraphElement> & { message?: string }) {
    return message ? (
        <p {...props} className={cn('flex items-start gap-2', className)}>
            {/* Kraken's error dot, drawn rather than loaded. */}
            <span
                aria-hidden="true"
                className="mt-1.25 size-1.5 shrink-0 rounded-full bg-border-error"
            />
            <span className="flex-1 type-caption text-text-error">
                {message}
            </span>
        </p>
    ) : null;
}
