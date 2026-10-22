/**
 * Opening, refreshing and closing the resident's session.
 *
 * THE TOKEN goes to the secure store and nowhere else (invariant 6): never the key-value
 * store, never Redux, never a log. What is kept on disk is display facts only — a name, the
 * estate, the unit — so the app opens with a name on it before the network has answered.
 *
 * `lastEstate` survives a sign-out on purpose: a returning resident is not asked which estate
 * they live on every time, and the splash sends a phone that has signed in before to Log In
 * rather than Sign Up. It is a preference, not a credential.
 */

import type { ThunkDispatch, UnknownAction } from '@reduxjs/toolkit'
import { getStorage, jsonStore } from '@gs/core'
import type { Api } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
import { PRINCIPAL_KEY, clearEverything, claimApproved, endServerSession, principalUpdated, sessionRestored, signedOut, tokenStorage, type Principal } from '@gs/core/auth'
import { clearNotifications } from '@gs/core/notify'
import { pushRegistration } from '@/features/notify/pushRegistration'
import { forgetQueue, startOfflineRuntime } from '@/offline/runtime'

type Dispatch = ThunkDispatch<unknown, unknown, UnknownAction>
type Verified = Api.AuthOtpVerifyResponse
type Me = Api.MeShowResponse

export type Estate = { id: string; name: string }

const estateBox = () => jsonStore<Estate>(getStorage(), 'pref:last_estate', 'v1')
let rememberedEstate: Estate | null = null

export const lastEstate = {
  get: (): Estate | null => (rememberedEstate ??= estateBox().get()),
  set(estate: Estate): void {
    rememberedEstate = estate
    estateBox().set(estate)
  },
}

type Cached = { principal: Principal; pending: boolean; demo: boolean; reviewer: boolean }

const cache = () => jsonStore<Cached>(getStorage(), PRINCIPAL_KEY, 'v1')
const save = (principal: Principal, pending: boolean, demo: boolean, reviewer: boolean) => cache().setDurable({ principal, pending, demo, reviewer })

/** Is the session on disk a demonstration one? Read when the answer is not to hand. */
const savedDemo = (): boolean => cache().get()?.demo === true

/** …and is it the app-store reviewer's account? Same reason: `/me` does not say. */
const savedReviewer = (): boolean => cache().get()?.reviewer === true

/** A verified one-time code: keep the token, remember who this is, and say where to go next. */
export async function openSession(result: Verified, dispatch: Dispatch): Promise<'claim_unit' | 'await_approval' | 'home'> {
  await tokenStorage.set('resident', result.token)

  const pending = result.account.status !== 'active'
  const principal: Principal = {
    id: Number(result.account.id),
    name: result.account.full_name ?? '',
    siteName: result.estate.name,
    reference: result.claim?.submitted_unit ?? null,
  }
  /*
   * `reviewer` IS THE SERVER'S WORD AND THE APP'S ONLY SOURCE FOR IT (the catalogue's own
   * line on `auth.otp.verify` is "Draw the demonstration banner from it"). It was being read off
   * the wire and dropped, so somebody signing in on the full demonstration account — the static
   * `246810` path the review notes give a reviewer — got no banner at all, and the only thing
   * telling them the estate was invented was its name. It is `false` for every real resident.
   */
  const reviewer = result.reviewer === true

  await save(principal, pending, false, reviewer)
  lastEstate.set({ id: String(result.estate.id), name: result.estate.name })
  dispatch(sessionRestored({ principal, pending, reviewer }))
  if (!pending) await startOfflineRuntime()

  return result.next === 'home' || result.next === 'claim_unit' || result.next === 'await_approval' ? result.next : pending ? 'await_approval' : 'home'
}

/**
 * "EXPLORE A DEMO COMMUNITY" — a look round the demonstration estate with no account
 * and no code, and no way to change anything in it.
 *
 * READ-ONLY IS THE SERVER'S DOING, NOT THE APP'S. `demo.enter` hands back the Resident App's
 * abilities with every `:write` struck out, so a write is refused by the route's own ability
 * middleware before a controller is reached. The app cannot grant itself more by forgetting to
 * hide a button, and this flag is only how the screens SAY what the token already is.
 *
 * The token is kept exactly like a real one — the secure store, never anywhere else — because
 * it is a real token. What is NOT done is `lastEstate`: a phone that looked round the
 * demonstration must not come back to Log In offering it as home.
 *
 * The offline runtime is not started either. There is nothing to queue: every write is refused,
 * and a queue that could only ever hold refusals is worse than none.
 */
export async function openDemoSession(result: Api.DemoEnterResponse, dispatch: Dispatch): Promise<'home'> {
  await tokenStorage.set('resident', result.token)

  const principal: Principal = {
    id: Number(result.account.id),
    name: result.account.full_name ?? '',
    siteName: result.estate.name,
    reference: null,
  }
  await save(principal, false, true, true)
  dispatch(sessionRestored({ principal, pending: false, demo: true, reviewer: true }))

  return 'home'
}

/** `GET /me` is the truth about the account: a claim approved since last time activates it on the spot. */
export async function refreshFromMe(me: Me, current: Principal | null, wasPending: boolean, dispatch: Dispatch): Promise<'pending' | 'active'> {
  const unit = me.unit ? [me.unit.phase, me.unit.reference].filter(Boolean).join(' · ') : (me.claim?.submitted_unit ?? null)
  const principal: Principal = {
    id: Number(me.account.id),
    name: me.resident?.full_name ?? me.account.full_name ?? current?.name ?? '',
    siteName: me.estate.name,
    reference: unit,
  }
  const active = me.account.status === 'active'
  // `savedDemo()` and not `false`: /me is asked again and again, and a demonstration session
  // that lost its flag on the first refresh would stop saying it was one. `me.show` carries
  // neither flag, so both are read back from what was written at sign-in.
  await save(principal, !active, savedDemo(), savedReviewer())
  dispatch(principalUpdated(principal))
  if (active && wasPending) {
    dispatch(claimApproved())
    await startOfflineRuntime()
  }
  return active ? 'active' : 'pending'
}

/**
 * The resident chose to leave: everything goes — the screen that calls this warns first if
 * anything is unsent.
 *
 * AND THE SERVER IS TOLD (M6, Q-M42). Until this existed, signing out deleted the phone's own
 * copy of the token and left the row on the server valid for ever — so a resident who signed
 * out on a borrowed or sold handset left a live credential behind them. `DELETE /session`
 * destroys it. The order matters: everything that NEEDS the token happens first, and killing
 * the token is the last act before the phone is wiped.
 *
 * With no signal the server cannot be told; the resident is signed out of the phone anyway
 * (they asked to be) and the session times out by itself inside the idle window. Nothing here
 * ever claims the server was told when it was not.
 */
export async function closeSession(dispatch: Dispatch): Promise<void> {
  const estate = lastEstate.get()
  // FIRST, while the API token still exists: the server stops pushing this account's alerts to this phone.
  // Best effort and never queued — with no signal the phone gives up its device token instead. It never throws.
  await pushRegistration.signOut()
  // What is on the lock screen goes too: a banner can name a visitor or a notice.
  await clearNotifications()
  // LAST of the network acts, because it is the one that makes the others impossible. Bounded, never retried.
  await endServerSession(() => dispatch(residentApi.endpoints.authSignOut.initiate(undefined, { track: false })))
  await clearEverything('resident', dispatch, 'signed_out')
  // The queue is gone from disk; its copy in memory goes too, so "deleted" is true whichever screen signed out.
  forgetQueue()
  if (estate) lastEstate.set(estate)
  dispatch(signedOut())
}
