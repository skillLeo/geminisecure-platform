/** "Marcus Whyte" → "MW". Two letters at most; a dash when there is no name to take them from. */
export const initialsOf = (name: string): string => name.trim().split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]!.toUpperCase()).join('') || '—'
