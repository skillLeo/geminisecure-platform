/**
 * TELLING THE SERVER THE PERSON HAS SIGNED OUT (M6, Q-M42).
 *
 * "Sign out" used to be a local act only: the phone deleted its own copy of the token and
 * the row on the server lived for ever, so a token ever copied off a handset — a backup, a
 * cable, a phone that was sold — kept working. `DELETE /api/v1/session` destroys it, and
 * this is the small amount of judgement around that one call.
 *
 * WHAT COUNTS AS "TOLD":
 *   200            the token is destroyed. Told.
 *   401            the token was already dead on the server — it had been revoked, or its
 *                  idle window had closed. The session IS over there, which is the thing
 *                  we wanted, so: told.
 *   anything else  not told. No signal, a timeout, a 5xx.
 *
 * IT IS NEVER QUEUED AND NEVER RETRIED. It names the very token that is about to be dropped
 * from this phone, so a copy kept for later could never be sent at all. It is also bounded:
 * a guard handing a phone back at the end of a watch is not kept on a spinner by a dead
 * connection, exactly as `notify/registration.ts` bounds its own sign-out.
 *
 * NOT TOLD IS NOT A FAILURE TO SIGN OUT. The person asked to leave this phone and they do,
 * every time — the token, the caches and the queue go whatever the network did. What the app
 * must not do is claim the server was told when it was not; the screen that asks says so
 * before the person taps, and the session ends by itself inside the idle window either way.
 */

/**
 * The shape an RTK Query mutation resolves to. Typed here so this file needs no RTK import.
 *
 * `error` is `unknown` rather than "something with a status", because RTK's error union also
 * holds a `SerializedError` — a thrown JavaScript error with no status at all. Narrowing it
 * here, once, is better than every caller casting.
 */
export type ServerAnswer = { data?: unknown; error?: unknown }

/** The status on an RTK error, when it has one. A `SerializedError` does not, and that is "not told". */
const statusOf = (error: unknown): unknown =>
  typeof error === 'object' && error !== null && 'status' in error ? (error as { status: unknown }).status : undefined

/** How long signing out waits for the server, and no longer. The same four seconds push de-registration waits. */
export const SIGN_OUT_WAIT_MS = 4000

export async function endServerSession(send: () => Promise<ServerAnswer>, waitMs: number = SIGN_OUT_WAIT_MS): Promise<boolean> {
  const answer = await new Promise<ServerAnswer | null>((resolve) => {
    const timer = setTimeout(() => resolve(null), waitMs)
    Promise.resolve()
      .then(send)
      .then(
        (value) => { clearTimeout(timer); resolve(value) },
        () => { clearTimeout(timer); resolve(null) },
      )
  })

  if (answer === null) return false
  if (!answer.error) return true

  // Already gone on the server: revoked, or the idle window closed. Either way this session is over there.
  return statusOf(answer.error) === 401
}
