import * as React from "react"

import { cn } from "@/lib/utils"

function Textarea({ className, ...props }: React.ComponentProps<"textarea">) {
  return (
    <textarea
      data-slot="textarea"
      className={cn(
        // Kraken "Text Area": the Text Field control, taller.
        "flex min-h-24 w-full min-w-0 rounded-kraken-sm border border-border-field bg-surface-field px-3.5 py-3.5 type-input text-text-primary caret-border-focus outline-none transition-colors duration-fast ease-out placeholder:text-text-placeholder hover:border-border-input focus:border-border-focus disabled:pointer-events-none disabled:opacity-40",
        "aria-invalid:border-border-error",
        className
      )}
      {...props}
    />
  )
}

export { Textarea }
