import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Button } from "./Button.vue"

export const buttonVariants = cva(
  "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium motion-interaction disabled:pointer-events-none disabled:bg-disabled disabled:text-disabled disabled:shadow-none [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring focus-visible:ring-2 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
  {
    variants: {
      variant: {
        default:
          "bg-primary text-primary-foreground hover:bg-brand-bold-hovered active:bg-brand-bold-pressed",
        destructive:
          "bg-danger-bold text-inverse hover:bg-danger-bold-hovered active:bg-danger-bold-pressed",
        success:
          "bg-success-bold text-inverse hover:bg-success-bold-hovered active:bg-success-bold-pressed",
        create:
          "bg-success-solid text-success-solid-foreground hover:bg-success-solid-hovered active:bg-success-solid-pressed",
        warning:
          "bg-warning-bold text-warning-inverse hover:bg-warning-bold-hovered active:bg-warning-bold-pressed",
        outline:
          "border border-input bg-background hover:bg-neutral-subtle-hovered active:bg-neutral-subtle-pressed",
        secondary:
          "bg-secondary text-secondary-foreground hover:bg-neutral-hovered active:bg-neutral-pressed",
        ghost:
          "hover:bg-neutral-subtle-hovered active:bg-neutral-subtle-pressed",
        plain: "",
        link: "text-link underline-offset-4 hover:text-link-pressed hover:underline active:text-link-pressed",
      },
      size: {
        "default": "h-8 px-3 py-1.5 has-[>svg]:px-2.5",
        "sm": "h-7 rounded-md gap-1.5 px-2.5 has-[>svg]:px-2",
        "lg": "h-9 rounded-md px-5 has-[>svg]:px-3.5",
        "icon": "size-8",
        "icon-sm": "size-7",
        "icon-lg": "size-9",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  },
)
export type ButtonVariants = VariantProps<typeof buttonVariants>
