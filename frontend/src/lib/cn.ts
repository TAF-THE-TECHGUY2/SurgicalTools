import clsx from 'clsx'
import type { ClassValue } from 'clsx'

/** Tailwind-friendly className combiner. */
export function cn(...inputs: ClassValue[]): string {
  return clsx(inputs)
}
