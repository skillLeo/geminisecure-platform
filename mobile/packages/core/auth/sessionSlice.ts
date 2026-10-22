/**
 * Who is signed in — and nothing that would let anyone else be.
 *
 * The slice holds the session's STATUS and the principal's display facts. It never
 * holds the token (see tokenStorage). The router guards read `status`:
 *
 *   booting     splash is up; token and cached principal are being read
 *   signedOut   no token — guard: enrolment; resident: sign in
 *   pending     resident only — token exists, the unit claim awaits the estate
 *   active      token exists and the server accepts it
 */

import { createSlice, type PayloadAction } from '@reduxjs/toolkit'

export type SessionStatus = 'booting' | 'signedOut' | 'pending' | 'active'

export type Principal = {
  id: number
  name: string
  /** Guard: the site posted to. Resident: the estate. */
  siteName: string | null
  /** Guard: employee number. Resident: unit label, e.g. "Phase 2 · Lot 47". */
  reference: string | null
}

export type SessionState = {
  status: SessionStatus
  principal: Principal | null
  /**
   * Why the last session ended, shown once on the sign-in screen. The three are
   * different facts and each needs a different sentence:
   *
   *   revoked     the SERVER ended it — a rebind, a new sign-in on this install, the office
   *   expired     nobody ended it: the phone sent nothing for thirty minutes and the idle
   *               window closed (M6, Q-M42). Nothing is wrong and nothing was taken away
   *   signed_out  the person chose to leave
   *
   * `revoked` and `expired` both keep everything saved without signal; only a chosen
   * sign-out clears it (auth/clearEverything.ts).
   */
  endedReason: 'revoked' | 'expired' | 'signed_out' | null
  /**
   * A READ-ONLY look round the demonstration community — "Explore a demo community",
   * and nothing else sets it. The server's token is what makes the session read-only; this is
   * how the screens know to SAY so, on every one of them, for as long as it lasts.
   */
  demo: boolean
  /**
   * THE ACCOUNT CARRIES THE SERVER'S `reviewer` FLAG — which is not the same thing as
   * `demo`, and the difference is what the banner has to say.
   *
   *   demo && reviewer    "Explore a demo community": the demonstration estate, READ-ONLY. The
   *                       token holds no write ability at all, so nothing in the session can
   *                       change anything anywhere.
   *   reviewer only       the full demonstration account, signed in with its static code. Same
   *                       invented estate, but it CAN write — a reviewer is meant to be able to
   *                       issue a pass and cast the demonstration ballot. Telling this session
   *                       "nothing you tap can change anything" would be a plain lie.
   *
   * It comes from `auth.otp.verify.reviewer` / `demo.enter.reviewer` and from nowhere else: no
   * build flag, nothing the phone decides, and `false` for every real resident.
   */
  reviewer: boolean
}

const initialState: SessionState = { status: 'booting', principal: null, endedReason: null, demo: false, reviewer: false }

const sessionSlice = createSlice({
  name: 'session',
  initialState,
  reducers: {
    sessionRestored(state, action: PayloadAction<{ principal: Principal | null; pending?: boolean; demo?: boolean; reviewer?: boolean }>) {
      state.status = action.payload.pending ? 'pending' : 'active'
      state.principal = action.payload.principal
      state.endedReason = null
      state.demo = action.payload.demo === true
      // A read-only look round the demonstration estate is a reviewer's session too, whether or
      // not the server said so in as many words: it is only ever reached through that one door.
      state.reviewer = action.payload.reviewer === true || action.payload.demo === true
    },
    principalUpdated(state, action: PayloadAction<Principal>) {
      state.principal = action.payload
    },
    claimApproved(state) {
      if (state.status === 'pending') state.status = 'active'
    },
    noSession(state) {
      state.status = 'signedOut'
      state.principal = null
      state.demo = false
      state.reviewer = false
    },
    /** A 401: the token was revoked — a rebind, a new sign-in on this install, or the office. */
    sessionRevoked(state) {
      state.status = 'signedOut'
      state.principal = null
      state.endedReason = 'revoked'
      state.demo = false
      state.reviewer = false
    },
    /**
     * A 401 `session_expired`: nothing was sent from this phone for thirty minutes and the
     * server closed the session by itself (M6, Q-M42 — "Automatically logs him out within
     * 30 minutes"). Nobody revoked anything, so the screens must not say anybody did.
     */
    sessionExpired(state) {
      state.status = 'signedOut'
      state.principal = null
      state.endedReason = 'expired'
      state.demo = false
      state.reviewer = false
    },
    signedOut(state) {
      state.status = 'signedOut'
      state.principal = null
      state.endedReason = 'signed_out'
      state.demo = false
      state.reviewer = false
    },
  },
})

export const { sessionRestored, principalUpdated, claimApproved, noSession, sessionRevoked, sessionExpired, signedOut } = sessionSlice.actions
export const sessionReducer = sessionSlice.reducer
