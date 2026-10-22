import { clsx, type ClassValue } from 'clsx'
import { twMerge } from 'tailwind-merge'

/** Merge class names; later Tailwind classes win over earlier conflicting ones. */
export const cn = (...inputs: ClassValue[]): string => twMerge(clsx(inputs))
