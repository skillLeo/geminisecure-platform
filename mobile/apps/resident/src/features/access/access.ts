/**
 * What this person may do in the Resident App — pure, so it is proven without a phone.
 *
 * THE SERVER IS THE RULE. `me.show.access.may` is read from the matrix the server enforces on every request,
 * and anything it does not allow is refused there (`403 not_for_your_access_level`) whatever this phone draws.
 * This only keeps the phone from offering what would be refused.
 *
 * OPTIMISTIC WHEN IT DOES NOT KNOW. An older server sends no `access`, and a phone that has never had an answer
 * has none either: both draw everything, as before levels existed, and the server decides. Panic and the
 * person's own e-pass are never among what can be taken away, so they are never asked about here.
 */

import type { Api } from '@gs/core/api'

export type Access = NonNullable<Api.MeShowResponse['access']>
export type MayKey = keyof Access['may']

/** Whether this person may do `key`: the server's answer, or yes while there is none. */
export const mayFrom = (access: Access | null | undefined, key: MayKey): boolean => (access?.may ? access.may[key] !== false : true)

const PRIMARY_FALLBACK = "your household's primary resident"

/** Who to ask, by name when the server knows it (`access.primary_name`), else the role (G9). */
export const primaryOf = (access: Access | null | undefined): string => {
  const name = (access?.primary_name ?? '').trim()
  return name || PRIMARY_FALLBACK
}

/** "Ask Andrea Fletcher to change your access." */
export const askSentence = (access: Access | null | undefined): string => `Ask ${primaryOf(access)} to change your access.`

/** A Helper's Profile, in place of the household's details. */
export const keptBySentence = (access: Access | null | undefined): string => `Your household's details are kept by ${primaryOf(access)}.`

/** What a screen says in place of what a level does not include — what it is for, and who to ask. */
export const NOT_FOR_LEVEL = {
  visitor_passes: "Visitor passes are issued by the household's Members.",
  dues: "Dues are for the household's Members.",
  notices: 'Estate notices go to residents with Member or Dependent access.',
  meetings: "Meetings are for the household's Members.",
  elections: "Voting is for the household's Members: the household still has its one ballot.",
} as const

/** The EmptyState body for a screen this level does not include: the reason, the person's access, who to ask. */
export function notForLevelSentence(access: Access | null | undefined, what: keyof typeof NOT_FOR_LEVEL): string {
  const level = access?.level_label ? ` Your access is ${access.level_label}.` : ''
  return `${NOT_FOR_LEVEL[what]}${level} ${askSentence(access)}`
}
