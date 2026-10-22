/**
 * This person's access in the app, as the server last said it (`me.show.access`), kept on the phone.
 *
 * One `me.show` read, shared with every other screen that asks (the query is de-duplicated), and its saved copy
 * — so a phone with no signal still draws what this level includes. `may(key)` is yes while nothing is known
 * (an older server, or no answer yet): the server decides, and nothing extra is drawn before the first answer.
 * See `access.ts`.
 */

import { useCallback } from 'react'
import { isFidelity, useOfflineCache } from '@gs/core'
import type { Api } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
import { mayFrom, type Access, type MayKey } from './access'

export type AccessModel = {
  /** Null until the server has said, or on a server older than levels. */
  access: Access | null
  may: (key: MayKey) => boolean
}

export function useAccess(): AccessModel {
  const me = residentApi.useMeShowQuery(undefined, { skip: isFidelity })
  const saved = useOfflineCache<Api.MeShowResponse>('me.show', me.data)
  const access = saved?.access ?? null

  const may = useCallback((key: MayKey) => mayFrom(access, key), [access])

  return { access, may }
}
