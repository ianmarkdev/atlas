import * as React from "react"

import { cn } from "@/lib/utils"

function Input({ className, type, ...props }: React.ComponentProps<"input">) {
  return (
    <input
      type={type}
      data-slot="input"
      className={cn(
        // Kraken "Text Field": focus is the 1px lime border, not a ring.
        "flex h-12 w-full min-w-0 rounded-kraken-sm border border-border-field bg-surface-field px-3.5 type-input text-text-primary caret-border-focus outline-none transition-colors duration-fast ease-out placeholder:text-text-placeholder hover:border-border-input focus:border-border-focus disabled:pointer-events-none disabled:opacity-40",
        "file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-text-primary",
        "aria-invalid:border-border-error",
        className
      )}
      {...props}
    />
  )
}

export { Input }
