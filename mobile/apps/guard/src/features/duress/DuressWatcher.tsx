/**
 * Keeps the one duress alert's record true with no screen open — mounted once, in the root layout (B124).
 *
 * A SILENT duress is raised from Home and its guard is trained never to open the Duress screen while they are
 * watched — and that screen was the only thing that ever asked what happened to the alert. So an alert dispatch
 * had closed stayed "active" on the phone for good: the next duress, silent or audible, was dropped without being
 * sent, the alertness checks were held back and recorded as missed, and sign-out stayed refused.
 *
 * This asks the same questions the screen asks, from the root, while there is something to ask about: a held
 * alert's fate from the queue, and an open one's from the server every few seconds. It shows nothing, says
 * nothing and makes no sound — a silent alert stays silent.
 *
 * ONLY WHILE SIGNED IN: the queue is read from disk once the session is active, and reading it before then would
 * read nothing and later overwrite what is there.
 */

import { isFidelity } from '@gs/core/fidelity'
import { useAppSelector } from '@/store'
import { useActiveDuress, useFollowDuress } from './useDuress'

export function DuressWatcher(): null {
  const signedIn = useAppSelector((s) => s.session.status === 'active')
  const active = useActiveDuress()
  useFollowDuress(signedIn ? active : null, isFidelity || !signedIn)
  return null
}
