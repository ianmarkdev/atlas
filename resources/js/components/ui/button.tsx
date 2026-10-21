import { Slot } from "@radix-ui/react-slot"
import { cva, type VariantProps } from "class-variance-authority"
import * as React from "react"

import { cn } from "@/lib/utils"

const buttonVariants = cva(
  "inline-flex cursor-pointer items-center justify-center gap-2 whitespace-nowrap type-button transition-colors duration-fast ease-out disabled:pointer-events-none disabled:opacity-40 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 [&_svg]:shrink-0 outline-none focus-ring-inverse aria-invalid:border-border-error",
  {
    variants: {
      variant: {
        // Kraken "Button / Primary": lime pill.
        default:
          "rounded-pill bg-surface-accent text-text-on-accent hover:bg-surface-accent-hover active:bg-surface-accent-pressed",
        destructive:
          "rounded-pill bg-border-error text-white hover:bg-border-error/85 active:bg-border-error/75",
        // Kraken "Button / Outline": square, lime line. Pressed fills with
        // color/border/accent-subtle, as in Figma.
        outline:
          "border border-border-accent text-text-accent hover:bg-surface-accent-subtle active:bg-border-accent-subtle",
        secondary:
          "rounded-pill border border-border-input text-text-primary hover:border-text-subtle hover:bg-surface-input-subtle",
        ghost:
          "rounded-kraken-md text-text-secondary hover:bg-surface-input-subtle hover:text-text-primary",
        link: "text-text-accent decoration-border-accent underline-offset-4 hover:underline",
      },
      size: {
        default: "h-10 px-7 has-[>svg]:px-5",
        sm: "h-8 px-4 has-[>svg]:px-3",
        lg: "h-12 px-8 has-[>svg]:px-6",
        icon: "size-9",
        // Kraken "Button / Block": the full-width form submit.
        block: "h-16 w-full rounded-kraken-sm px-6 type-submit",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  }
)

function Button({
  className,
  variant,
  size,
  asChild = false,
  ...props
}: React.ComponentProps<"button"> &
  VariantProps<typeof buttonVariants> & {
    asChild?: boolean
  }) {
  const Comp = asChild ? Slot : "button"

  return (
    <Comp
      data-slot="button"
      className={cn(buttonVariants({ variant, size, className }))}
      {...props}
    />
  )
}

export { Button, buttonVariants }
