import { Slot } from "@radix-ui/react-slot"
import { cva, type VariantProps } from "class-variance-authority"
import * as React from "react"

import { cn } from "@/lib/utils"

const badgeVariants = cva(
  "inline-flex items-center justify-center rounded-kraken-sm border px-2 py-1 type-pill w-fit whitespace-nowrap shrink-0 [&>svg]:size-3 gap-1 [&>svg]:pointer-events-none focus-ring aria-invalid:border-border-error transition-colors duration-fast ease-out overflow-hidden",
  {
    variants: {
      variant: {
        // Kraken "Service Pill", selected: solid lime.
        default:
          "border-surface-accent bg-surface-accent text-text-on-accent [a&]:hover:bg-surface-accent-hover",
        // Kraken "Service Pill": lime line on a 15% lime fill.
        secondary:
          "border-border-accent-subtle bg-surface-accent-subtle text-text-accent [a&]:hover:border-border-accent",
        destructive:
          "border-border-error bg-surface-error-subtle text-text-error",
        outline:
          "border-border-input text-text-secondary [a&]:hover:bg-surface-input-subtle [a&]:hover:text-text-primary",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  }
)

function Badge({
  className,
  variant,
  asChild = false,
  ...props
}: React.ComponentProps<"span"> &
  VariantProps<typeof badgeVariants> & { asChild?: boolean }) {
  const Comp = asChild ? Slot : "span"

  return (
    <Comp
      data-slot="badge"
      className={cn(badgeVariants({ variant }), className)}
      {...props}
    />
  )
}

export { Badge, badgeVariants }
