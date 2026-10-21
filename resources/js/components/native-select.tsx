import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';
import type { SelectOption } from '@/types';

/**
 * A plain `<select>` styled like `Input`. Submits with `<Form>` like any native field,
 * which keeps "no value" (empty string) simple.
 */
export function NativeSelect({
    options,
    placeholder,
    className,
    ...props
}: ComponentProps<'select'> & {
    options: SelectOption[];
    placeholder?: string;
}) {
    return (
        <select
            className={cn(
                'flex h-12 w-full cursor-pointer rounded-kraken-sm border border-border-field bg-surface-field px-3.5 type-input text-text-primary transition-colors duration-fast ease-out outline-none hover:border-border-input focus:border-border-focus disabled:pointer-events-none disabled:opacity-40 aria-invalid:border-border-error',
                className,
            )}
            {...props}
        >
            {/* The open option list is drawn by the browser; give it the theme colours (dark mode). */}
            {placeholder !== undefined && (
                <option value="" className="bg-background text-foreground">
                    {placeholder}
                </option>
            )}
            {options.map((option) => (
                <option
                    key={option.value}
                    value={option.value}
                    className="bg-background text-foreground"
                >
                    {option.label}
                </option>
            ))}
        </select>
    );
}
