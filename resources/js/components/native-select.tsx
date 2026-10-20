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
                'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm dark:bg-input/30',
                className,
            )}
            {...props}
        >
            {placeholder !== undefined && (
                <option value="">{placeholder}</option>
            )}
            {options.map((option) => (
                <option key={option.value} value={option.value}>
                    {option.label}
                </option>
            ))}
        </select>
    );
}
