import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Badge } from "./Badge.vue"

export const badgeVariants = cva(
  "inline-flex items-center justify-center rounded-full border px-2 py-0.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 [&>svg]:size-3 gap-1 [&>svg]:pointer-events-none focus-visible:border-ring focus-visible:ring-ring focus-visible:ring-2 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive transition-[color,box-shadow] overflow-hidden",
  {
    variants: {
      variant: {
        default:
          "border-transparent bg-primary text-primary-foreground [a&]:hover:bg-primary/90",
        secondary:
          "border-transparent bg-secondary text-secondary-foreground [a&]:hover:bg-secondary/90",
        destructive:
         "border-transparent bg-danger-bold text-inverse [a&]:hover:bg-danger-bold-hovered",
        success:
          "border-transparent bg-success-bold text-inverse [a&]:hover:bg-success-bold-hovered",
        successSolid:
          "border-transparent bg-success-solid text-success-solid-foreground [a&]:hover:bg-success-solid-hovered",
        warning:
          "border-transparent bg-warning-bold text-warning-inverse [a&]:hover:bg-warning-bold-hovered",
        info:
          "border-transparent bg-information-bold text-inverse [a&]:hover:bg-information-bold-hovered",
        outline:
          "text-foreground [a&]:hover:bg-accent [a&]:hover:text-accent-foreground",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  },
)
export type BadgeVariants = VariantProps<typeof badgeVariants>
