import * as TogglePrimitive from "@radix-ui/react-toggle"
import { cva, type VariantProps } from "class-variance-authority"
import * as React from "react"

import { cn } from "@/lib/utils"

const toggleVariants = cva(
  "inline-flex cursor-pointer items-center justify-center gap-2 rounded-kraken-md type-toggle text-text-secondary hover:bg-surface-input-subtle hover:text-text-primary disabled:pointer-events-none disabled:opacity-40 data-[state=on]:bg-surface-accent-subtle data-[state=on]:text-text-accent [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 [&_svg]:shrink-0 outline-none focus-ring transition-colors duration-fast ease-out aria-invalid:border-border-error",
  {
    variants: {
      variant: {
        default: "bg-transparent",
        outline:
          "border border-border-toggle bg-surface-toggle data-[state=on]:border-border-accent-subtle",
      },
      size: {
        default: "h-9 px-2 min-w-9",
        sm: "h-8 px-1.5 min-w-8",
        lg: "h-10 px-2.5 min-w-10",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  }
)

function Toggle({
  className,
  variant,
  size,
  ...props
}: React.ComponentProps<typeof TogglePrimitive.Root> &
  VariantProps<typeof toggleVariants>) {
  return (
    <TogglePrimitive.Root
      data-slot="toggle"
      className={cn(toggleVariants({ variant, size, className }))}
      {...props}
    />
  )
}

export { Toggle, toggleVariants }
