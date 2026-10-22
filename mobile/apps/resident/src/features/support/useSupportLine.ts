/**
 * GEMINI SECURITY'S PHONE LINE, AS THE CONSOLE SET IT (`support.contact`; the user, 8 Oct 2026).
 *
 * Null while the console has none — and then Help & support draws NO "Call Gemini Security": no placeholder number, no
 * button that rings nothing. Kept on the phone, so a line once read is still offered with no connection.
 */

import { isFidelity, useOfflineCache } from '@gs/core'
import type { Api } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'

export type SupportLine = {
  /** What to dial: "+18765550123" — or null, and then there is no call button. */
  phone: string | null
  /** As a person reads it: "+1 876 555 0123". */
  display: string | null
}

export function useSupportLine(): SupportLine {
  const read = residentApi.useSupportContactQuery(undefined, { skip: isFidelity })
  const contact = useOfflineCache<Api.SupportContactResponse>('support.contact', read.data)
  const phone = typeof contact?.phone === 'string' && contact.phone !== '' ? contact.phone : null

  return { phone, display: phone ? (contact?.phone_display ?? phone) : null }
}
