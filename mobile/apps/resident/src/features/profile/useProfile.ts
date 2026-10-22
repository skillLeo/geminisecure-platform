/**
 * The Profile tab — board resident-app-17. Who the resident is to the estate, who and what is on their
 * household's register, and the way out (Settings, Sign out).
 *
 *   READ      `me.show` (the resident, the unit), `me.household` (the household — and a fallback for the
 *             three lists when only Home has been opened), `members.index`, `vehicles.index`,
 *             `contacts.index`. Every one says "Serve the cache", so every one goes through
 *             `useOfflineCache` and the tab opens with no connection. Each card stands alone: a failure
 *             in one never blanks the others, and Sign out is there in EVERY state.
 *   403       the SERVER saying "not for this account". It outranks whatever this phone saved earlier and
 *             is never blamed on the connection: nothing saved is drawn under it. `GET /me` answers even
 *             an account with no home, so the whole-tab "not available yet" state is decided by the four
 *             HOUSEHOLD reads — not by `me.show`.
 *   STALE     "No connection" is said only when that is the reason; a server that answered with a failure
 *             is "could not be refreshed" (household.ts · stalenessOf).
 *   PHOTO     the account's profile photo on the hero — shown, taken or chosen, removed; queued like any change
 *             (useProfilePhoto.ts, R10 26 Sep).
 *   WRITES    only the two that are online only: removing a member (below), and sending, resending or
 *             cancelling a pending member's invite (useInvites.ts, beside each row). Adding a member, a
 *             vehicle or a contact, and changing one's own details, are queueable writes made on their own
 *             screens through `submit()`; this tab shows where each one stands (Pending sync · Syncing ·
 *             Not accepted) beside the server's rows.
 *   MEMBERS   adding and removing is the PRIMARY resident's right: the server says who that is
 *             (`can_manage_members`). The primary sees "Add" and a "Remove" beside each other member; anyone
 *             else sees one sentence saying who can. Removing is ONLINE ONLY and asked first, in a ConfirmSheet
 *             over the screen that names the person in full (`useMemberRemoval`, sheets.ts) — it takes a
 *             person's access away at once, so it is never queued to happen later. So are a Helper's days and
 *             hours, and pausing them (`useHoursChange`, 8 Oct 2026): the gate must hear a pause at once.
 *   ITEMS     a vehicle, a pet or an emergency contact opens its own screen to change or remove it (M50 §5.3–§5.4),
 *             and "+ Add …" sits at the foot of each card — for whoever the server lets change them (`mayEdit`).
 *   SIGN OUT  real (`closeSession`): the token, the caches and the queue all go. So whatever is still
 *             waiting is given one last chance to be sent (at once — a person asked), then NAMED and
 *             counted for the resident, who decides — in a ConfirmSheet over the tab. An emergency alert
 *             that has not reached the estate yet blocks it outright. What the sheet says is deleted IS
 *             deleted: the queue's copy in memory goes with the one on disk.
 */

import { useCallback, useEffect, useMemo, useState } from 'react'
import { router } from 'expo-router'
import { fidelityScreenId, isFidelity, useConfirmFlow, useOfflineCache, type ConfirmFlow } from '@gs/core'
import { isApiError } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
import { outboxChanged } from '@gs/core/offline'
import { profileFixture } from '@/fidelity/profile'
import { keptBySentence, mayFrom } from '@/features/access/access'
import { closeSession, refreshFromMe } from '@/features/auth/session'
import { useDemoRefusal } from '@/features/auth/useDemo'
import { settingsPrefs } from '@/features/settings/prefs'
import { drainNow, outbox } from '@/offline/runtime'
import { useAppDispatch, useAppSelector } from '@/store'
import {
  HOUSEHOLD_ENDPOINTS, REMOVE_OFFLINE, addRecordsOf, canManageMembers, contactRows, deniedSentence, detailsChange, initialsOf, isDenied, memberRows, petRows, roleTag, sectionStatusOf, staleSentence, stalenessOf, unitLine, unsentSummary, vehicleRows, waitingCount,
  type Contact, type HouseholdOp, type Level, type Member, type Pet, type QueueState, type ReadFailure, type Row, type SectionStatus, type Vehicle,
} from './household'
import { windowProblem, type HelperWindow } from './helperHours'
import { HOURS_OFFLINE_SHEET, ITEM_OFFLINE, LEVEL_OFFLINE_SHEET, REMOVE_NO_ANSWER, itemNoAnswer } from './sheets'
import { useProfilePhoto, type PhotoModel } from './useProfilePhoto'

export type { SectionStatus }
export type Section<T> = { status: SectionStatus; rows: Row<T>[]; message: string | null }

export type DetailsCard = {
  status: SectionStatus
  message: string | null
  /** The account signs in as an entry on the estate's register — the only place a phone and an email are kept. */
  linked: boolean
  email: string | null
  phone: string | null
  /** A change to these details that is still on this phone, or that the server did not accept. */
  change: { opId: string; state: Exclude<QueueState, 'confirmed'>; refusal: string | null } | null
}

export type SignOutModel = {
  asking: boolean
  working: boolean
  /** Why signing out is refused right now, or null. */
  blocked: string | null
  unsent: number
  /** What is still waiting, as each act was saved ("Vehicle · 1234 AB") — the first few, then how many more. */
  unsentShown: string[]
  unsentMore: number
  online: boolean
  /** Signing out did not finish — said, never swallowed. */
  problem: string | null
  ask: () => void
  dismiss: () => void
  confirm: () => void
}

export type ManageMembers = {
  /** true: the primary resident · false: a member (one sentence says who can) · null: not known yet (draw neither). */
  allowed: boolean | null
  /** The signed-in resident's own register id — never offered a "Remove" beside themselves. */
  selfId: number | string | null
  online: boolean
  /**
   * Removing a member, asked in a ConfirmSheet (`removal.target` is who). The server's own sentence when it refused,
   * or that it could not be reached, stays in the sheet (`removal.problem`); it closes only once they are removed.
   */
  removal: ConfirmFlow<Member>
  /** Changing a member's access level — the primary's, once the server has opened levels (`levelsOpen`). */
  levelChange: ConfirmFlow<LevelChange>
  levelsOpen: boolean
  /** A Helper's days, hours, end date and "pause now" — the primary's, for a member the server sends a window for. */
  hoursChange: ConfirmFlow<HoursChange>
}

export type ProfileModel = {
  fixture: typeof profileFixture | null
  hero: { name: string; initials: string; place: string; role: string | null }
  /** The profile photo on the hero, and changing it (R10, 26 Sep). */
  photo: PhotoModel
  details: DetailsCard
  members: Section<Member>
  /** When the server last answered the member list — an invite answered since is drawn until the list catches up. */
  membersFetchedAt: number | undefined
  manage: ManageMembers
  vehicles: Section<Vehicle>
  /** The household's pets (M50 §5.3d), pills beside the vehicles. */
  pets: Section<Pet>
  contacts: Section<Contact>
  /**
   * Whether this person may add, change and remove the household's vehicles, pets and emergency contacts — the
   * server's word (`access.may.household_edit`; a Member's, unless the estate's rules say otherwise). The "+ Add …"
   * rows and the screens a pill or a contact opens are offered on it alone.
   */
  mayEdit: boolean
  /** The account cannot see a household at all (403): one plain explanation instead of four. */
  denied: boolean
  deniedMessage: string
  /** A Helper's level does not include the household's details: who keeps them, in their place. Null otherwise. */
  keptBy: string | null
  /** Why what is drawn is the copy saved on this phone — the TRUE reason — or null when it is fresh. */
  staleNote: string | null
  /** Household changes saved on this phone and not yet sent. */
  waiting: number
  refreshing: boolean
  refresh: () => void
  /** The resident has read a refusal; it leaves the queue. (A pending act can never be dismissed.) */
  dismiss: (opId: string) => void
  signOut: SignOutModel
}

const householdOps = (): HouseholdOp[] => outbox.all().filter((op) => (HOUSEHOLD_ENDPOINTS as readonly string[]).includes(op.endpoint))

type QueryLike = { error?: unknown }
const failureOf = (q: QueryLike): ReadFailure => (isApiError(q.error) ? { status: q.error.status, code: q.error.code } : null)
const is403 = (q: QueryLike): boolean => isDenied(failureOf(q))
const sentenceOf = (q: QueryLike, what: string): string | null =>
  !q.error ? null
  : isApiError(q.error) ? (q.error.status === 'network' || q.error.status === 'timeout' ? `You're offline, and ${what} not saved on this phone yet. Check your connection and try again.` : q.error.message)
  : 'Something unexpected happened. Please try again.'

const statusOf = (has: boolean, q: QueryLike): SectionStatus => sectionStatusOf({ has, failed: !!q.error, failure: failureOf(q) })

/* ---- signing out --------------------------------------------------------------------------------- */

const ALERT_UNSENT = 'An emergency alert from this phone has not reached the estate yet. Signing out would delete it: stay signed in until it has been sent.'
const SIGN_OUT_UNFINISHED = 'Signing out did not finish, so you are still signed in on this phone. Try again.'

/** Asked in a ConfirmSheet (ProfileScreen · signOutWords), driven by confirmFlow: done once, a failure stays in the sheet. */
export function useSignOut(): SignOutModel {
  const dispatch = useAppDispatch()
  const online = useAppSelector((s) => s.network.online)
  const ops = useAppSelector((s) => s.outbox.ops)

  const waiting = ops.filter((op) => op.state === 'pending' || op.state === 'syncing')
  // Signing out deletes the queue. An emergency alert still in it has reached nobody yet.
  const alertUnsent = waiting.some((op) => op.endpoint === 'duress.store')
  const summary = unsentSummary(waiting.map((op) => op.label))

  const flow = useConfirmFlow<true>({
    send: async () => {
      if (alertUnsent) return { ok: false, message: ALERT_UNSENT }
      if (isFidelity) return { ok: false, message: SIGN_OUT_UNFINISHED }
      try {
        // One last chance for whatever is waiting — at once, whatever failed a moment ago — before anything is deleted.
        await drainNow().catch(() => undefined)
        // `working` is on screen by now: the tab has let go of its reads (see `skip` in useProfile). One turn of
        // the loop makes sure of it before the api cache is emptied.
        await new Promise<void>((resolve) => setTimeout(resolve, 0))
        await closeSession(dispatch)
        // The sheet said "deleted for good": the queue's copy in memory goes with the one on disk, so signing
        // straight back in cannot send what the resident was told is gone. Preferences held in memory go too.
        outbox.reset()
        dispatch(outboxChanged([]))
        settingsPrefs.reset()
        router.replace('/(auth)/log-in' as never)
        return { ok: true }
      } catch {
        return { ok: false, message: SIGN_OUT_UNFINISHED }
      }
    },
    failed: SIGN_OUT_UNFINISHED,
  })

  return {
    asking: flow.open,
    working: flow.working,
    blocked: alertUnsent ? ALERT_UNSENT : null,
    unsent: waiting.length,
    unsentShown: summary.shown,
    unsentMore: summary.more,
    online,
    problem: flow.problem,
    ask: () => {
      if (isFidelity) return
      flow.ask(true)
      void drainNow().catch(() => undefined)
    },
    dismiss: flow.cancel,
    confirm: flow.confirm,
  }
}

/* ---- removing a member --------------------------------------------------------------------------- */

/**
 * Removing a member: asked in a ConfirmSheet, then sent ONCE while connected — never through the queue, because it
 * takes a person's access away at once. `after` runs only once the server has said they are removed.
 */
export function useMemberRemoval(after: (member: Member) => void): ConfirmFlow<Member> {
  const online = useAppSelector((s) => s.network.online)
  const refusal = useDemoRefusal()
  const [removeMember] = residentApi.useMembersDestroyMutation()
  return useConfirmFlow<Member>({
    send: async (member) => {
      // Checked again at the press: the connection can go between drawing the sheet and pressing it.
      if (!online) return { ok: false, message: REMOVE_OFFLINE }
      if (refusal) return { ok: false, message: refusal }
      const answer = await removeMember({ member: String(member.id) })
      if ('error' in answer && answer.error) {
        const error = answer.error
        // No answer is not "no connection": a delete that timed out, or lost its connection on the way, may have gone through.
        return { ok: false, message: isApiError(error) ? (error.status === 'network' || error.status === 'timeout' ? REMOVE_NO_ANSWER : error.message) : 'That did not go through. Nobody was removed.' }
      }
      after(member)
      return { ok: true }
    },
    failed: 'That did not finish, so this phone cannot tell whether they were removed. Pull down to refresh your household.',
  })
}

/* ---- changing a member's access level ------------------------------------------------------------ */

/** Who, and the level asked about. Choosing another level in the sheet asks again, about that one. */
export type LevelChange = { member: Member; level: Level }

const LEVEL_NO_ANSWER = "That did not finish, so this phone cannot tell whether their access changed. Pull down to refresh your household."

/**
 * The primary resident changes a member's level (`members.level`): asked in a ConfirmSheet, sent ONCE while
 * connected — it takes effect on the member's next request. The server's refusal stays in the sheet
 * (`primary_is_member`, `access_levels_not_open`, …). `after` runs only once the server has said it changed.
 */
export function useLevelChange(after: (member: Member) => void): ConfirmFlow<LevelChange> {
  const online = useAppSelector((s) => s.network.online)
  const refusal = useDemoRefusal()
  const [setLevel] = residentApi.useMembersLevelMutation()
  return useConfirmFlow<LevelChange>({
    send: async ({ member, level }) => {
      if (!online) return { ok: false, message: LEVEL_OFFLINE_SHEET }
      if (refusal) return { ok: false, message: refusal }
      const answer = await setLevel({ member: String(member.id), access_level: level })
      if ('error' in answer && answer.error) {
        const error = answer.error
        return { ok: false, message: isApiError(error) ? (error.status === 'network' || error.status === 'timeout' ? LEVEL_NO_ANSWER : error.message) : "That did not go through. Their access is unchanged." }
      }
      after(member)
      return { ok: true }
    },
    failed: LEVEL_NO_ANSWER,
  })
}

/* ---- a helper's days and hours ------------------------------------------------------------------- */

/** Who, and the days and hours being set. Every change made in the sheet asks again, about the new ones. */
export type HoursChange = { member: Member; window: HelperWindow }

const HOURS_NO_ANSWER = 'That did not finish, so this phone cannot tell whether their days and hours changed. Pull down to refresh your household.'

/**
 * The primary resident sets when a Helper may come (`members.access`, the user, 8 Oct 2026): asked in a ConfirmSheet,
 * sent ONCE while connected — a pause blocks them at every gate at once, so it is never queued to happen later. The
 * server's refusal stays in the sheet. `after` runs only once the server has said they changed.
 */
export function useHoursChange(after: (member: Member) => void): ConfirmFlow<HoursChange> {
  const online = useAppSelector((s) => s.network.online)
  const refusal = useDemoRefusal()
  const [setHours] = residentApi.useMembersAccessMutation()
  return useConfirmFlow<HoursChange>({
    send: async ({ member, window }) => {
      if (!online) return { ok: false, message: HOURS_OFFLINE_SHEET }
      if (refusal) return { ok: false, message: refusal }
      const problem = windowProblem(window)
      if (problem) return { ok: false, message: problem }
      const answer = await setHours({ member: String(member.id), days: window.days, from: window.from, to: window.to, paused: window.paused, ...(window.until ? { until: window.until } : {}) })
      if ('error' in answer && answer.error) {
        const error = answer.error
        return { ok: false, message: isApiError(error) ? (error.status === 'network' || error.status === 'timeout' ? HOURS_NO_ANSWER : error.message) : 'That did not go through. Their days and hours are unchanged.' }
      }
      after(member)
      return { ok: true }
    },
    failed: HOURS_NO_ANSWER,
  })
}

/* ---- removing an emergency contact, a vehicle or a pet ------------------------------------------- */

export type Removable = 'contact' | 'vehicle' | 'pet'

/**
 * Removing one of the household's emergency contacts, vehicles or pets (`contacts.destroy`, `vehicles.destroy`,
 * `pets.destroy`) — from inside its own screen (M50 §5.3c, §5.4c), never on the row: asked in a ConfirmSheet, sent ONCE
 * while connected — the contract says online only, because the list changes at once. `after` runs only once the
 * server has said it is removed.
 */
export function useItemRemoval<T extends { id: number | string }>(what: Removable, after: (item: T) => void): ConfirmFlow<T> {
  const online = useAppSelector((s) => s.network.online)
  const refusal = useDemoRefusal()
  const [removeContact] = residentApi.useContactsDestroyMutation()
  const [removeVehicle] = residentApi.useVehiclesDestroyMutation()
  const [removePet] = residentApi.usePetsDestroyMutation()
  return useConfirmFlow<T>({
    send: async (item) => {
      if (!online) return { ok: false, message: ITEM_OFFLINE }
      if (refusal) return { ok: false, message: refusal }
      const answer = what === 'contact' ? await removeContact({ contact: String(item.id) }) : what === 'pet' ? await removePet({ pet: String(item.id) }) : await removeVehicle({ vehicle: String(item.id) })
      if ('error' in answer && answer.error) {
        const error = answer.error
        return { ok: false, message: isApiError(error) ? (error.status === 'network' || error.status === 'timeout' ? itemNoAnswer(what) : error.message) : `That did not go through. The ${what} is still on your list.` }
      }
      after(item)
      return { ok: true }
    },
    failed: itemNoAnswer(what),
  })
}

/**
 * Removing from the item's own screen (M50 §5.3c, §5.4c): once the server has said it is gone, the queue's record of
 * having ADDED it goes too — or Profile would draw it back until the list caught up — and the screen closes.
 */
export function useRemoveHere<T extends { id: number | string }>(what: Removable, done: () => void): ConfirmFlow<T> {
  return useItemRemoval<T>(what, (item) => {
    const added = what === 'contact' ? 'contacts.store' : what === 'pet' ? 'pets.store' : 'vehicles.store'
    for (const opId of addRecordsOf(householdOps(), item.id, added)) void outbox.dismiss(opId)
    done()
  })
}

/* ---- the tab ------------------------------------------------------------------------------------- */

export function useProfile(): ProfileModel {
  const fixture = isFidelity && fidelityScreenId() === 'resident-app-17' ? profileFixture : null
  // Nothing is asked without an ACTIVE session: a signed-out phone is on its way to Log In, and a
  // pending account's token reaches only `/me` and the unit claim.
  const active = useAppSelector((s) => s.session.status === 'active')
  const signOut = useSignOut()
  // ...and nothing once the resident is LEAVING. Signing out empties the api cache while this tab is still
  // mounted; a read still subscribed is asked again on the spot — with no token — and its 401 arrives as
  // "your session was ended", seconds later, on top of whoever is signing in next (it aborted the sign-in
  // in the live check). So this tab lets go of its five reads BEFORE the session is closed.
  const skip = isFidelity || !active || signOut.working
  const dispatch = useAppDispatch()
  const principal = useAppSelector((s) => s.session.principal)
  const online = useAppSelector((s) => s.network.online)
  const view = useAppSelector((s) => s.outbox.ops)

  const me = residentApi.useMeShowQuery(undefined, { skip })
  const savedMe = useOfflineCache('me.show', me.data)
  // A Helper's level does not include the household's details (`access.may.household`): those four are not asked
  // for — the server would refuse them — and the tab says who keeps them instead. Unknown = asked, as before levels.
  const readsHousehold = mayFrom(savedMe?.access, 'household')
  const skipHousehold = skip || !readsHousehold
  const household = residentApi.useMeHouseholdQuery(undefined, { skip: skipHousehold })
  const savedHousehold = useOfflineCache('me.household', household.data)
  const members = residentApi.useMembersIndexQuery(undefined, { skip: skipHousehold })
  const savedMembers = useOfflineCache('members.index', members.data)
  const vehicles = residentApi.useVehiclesIndexQuery(undefined, { skip: skipHousehold })
  const savedVehicles = useOfflineCache('vehicles.index', vehicles.data)
  const contacts = residentApi.useContactsIndexQuery(undefined, { skip: skipHousehold })
  const savedContacts = useOfflineCache('contacts.index', contacts.data)

  // `GET /me` is the truth about who this is: a name or a unit the estate has corrected reaches the rest of the app.
  const fresh = me.data
  const freshName = fresh ? (fresh.resident?.full_name ?? fresh.account.full_name ?? '') : null
  const freshUnit = fresh?.unit ? [fresh.unit.phase, fresh.unit.reference].filter(Boolean).join(' · ') : null
  useEffect(() => {
    if (!fresh || !principal || fresh.account.status !== 'active') return
    if (freshName === principal.name && (freshUnit === null || freshUnit === principal.reference) && fresh.estate.name === principal.siteName) return
    void refreshFromMe(fresh, principal, false, dispatch)
    // The answer is what this follows; `principal` changes as a result of it.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [fresh, freshName, freshUnit, dispatch])

  // The store mirrors the queue without bodies or answers; the queue itself has both.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const ops = useMemo<readonly HouseholdOp[]>(() => (skip ? [] : householdOps()), [view, skip])

  const [refreshing, setRefreshing] = useState(false)
  const refetchers = [me.refetch, household.refetch, members.refetch, vehicles.refetch, contacts.refetch]
  const refresh = useCallback(() => {
    if (skip) return
    setRefreshing(true)
    void drainNow().catch(() => undefined) // a person pulled the tab down: go at once
    // Only what was asked for: a query this level never started cannot be asked again.
    const asked = skipHousehold ? [me.refetch] : refetchers
    void Promise.allSettled(asked.map((refetch) => refetch())).finally(() => setRefreshing(false))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [skip, skipHousehold, ...refetchers])

  const dismiss = useCallback((opId: string) => void outbox.dismiss(opId), [])

  // Removing a member: asked in a sheet, then sent ONCE while connected — never through the queue.
  const removal = useMemberRemoval((member) => {
    // The queue's record of having added them goes too, or it would draw them back once the list no longer holds them.
    for (const opId of addRecordsOf(householdOps(), member.id)) void outbox.dismiss(opId)
    void members.refetch()
    void household.refetch()
  })
  const levelChange = useLevelChange(() => {
    void members.refetch()
    void household.refetch()
  })
  const hoursChange = useHoursChange(() => {
    void members.refetch()
    void household.refetch()
  })
  const manageBase = { online, removal, levelChange, hoursChange, levelsOpen: savedMe?.access?.levels_open === true }
  // Vehicles, pets and emergency contacts are changed and removed from their own screens (M50 §5.3–§5.4), on the
  // server's word for this person's access (`household_edit`). Unknown = offered, as before levels.
  const mayEdit = mayFrom(savedMe?.access, 'household_edit')

  // The profile photo (R10): the account's own, from `me.show` — none on the board, none for an account with no home.
  const noHome = readsHousehold && [household, members, vehicles, contacts].every(is403)
  const ownMe = !is403(me) && savedMe && (!principal || String(savedMe.account.id) === String(principal.id)) ? savedMe : undefined
  const photo = useProfilePhoto({ shape: ownMe?.account.photo ?? null, serverAt: me.fulfilledTimeStamp, enabled: !skip && !fixture && !noHome })

  if (fixture) {
    const resident = fixture.me.resident
    return {
      fixture,
      hero: { name: resident.full_name, initials: initialsOf(resident.full_name), place: unitLine(fixture.me.estate.name, fixture.me.unit), role: roleTag(resident) },
      photo,
      details: { status: 'ready', message: null, linked: true, email: resident.email, phone: resident.phone, change: null },
      members: { status: 'ready', rows: memberRows(fixture.members, []), message: null },
      membersFetchedAt: undefined,
      // The board draws the primary resident's view: the "Add" row, and no remove controls (it has none).
      manage: { ...manageBase, allowed: true, selfId: null },
      vehicles: { status: 'ready', rows: vehicleRows(fixture.vehicles, []), message: null },
      // The board's pet is drawn from its own words (fixture.board.pet), not as a row.
      pets: { status: 'ready', rows: [], message: null },
      contacts: { status: 'ready', rows: contactRows(fixture.contacts, []), message: null },
      mayEdit: true,
      denied: false,
      deniedMessage: '',
      keptBy: null,
      staleNote: null,
      waiting: 0,
      refreshing: false,
      refresh,
      dismiss,
      signOut,
    }
  }

  // A saved answer is only shown if it is THIS account's — and never under a 403 (the server's "not for this account").
  const cachedMe = !is403(me) && savedMe && (!principal || String(savedMe.account.id) === String(principal.id)) ? savedMe : undefined
  const cachedHousehold = is403(household) ? undefined : savedHousehold

  const resident: Member | null = cachedMe?.resident ?? null
  const name = resident?.full_name ?? cachedMe?.account.full_name ?? principal?.name ?? ''
  // The unit: `me.show`'s, else `me.household`'s, else what the session remembers (which may hold none — then the estate alone).
  const place = cachedMe ? unitLine(cachedMe.estate.name, cachedMe.unit ?? cachedHousehold?.unit ?? null) : unitLine(principal?.siteName, cachedHousehold?.unit ?? { reference: principal?.reference })

  const change = detailsChange(ops)
  // What the resident typed is shown while it waits — marked as not sent yet; a refusal shows the server's values again.
  const showing = change && change.state !== 'refused' ? change.body : {}
  const shown = (typed: string | undefined, server: string | null | undefined): string | null => (typed !== undefined ? typed.trim() || null : (server ?? null))

  const memberItems: readonly Member[] | undefined = is403(members) ? undefined : (savedMembers?.items ?? cachedHousehold?.members)
  const vehicleItems: readonly Vehicle[] | undefined = is403(vehicles) ? undefined : (savedVehicles?.items ?? cachedHousehold?.vehicles)
  const contactItems: readonly Contact[] | undefined = is403(contacts) ? undefined : (savedContacts?.items ?? cachedHousehold?.emergency_contacts)

  const queries = [me, household, members, vehicles, contacts]
  // `GET /me` answers 200 for an account with no home, so it cannot decide this: the four household reads do.
  const householdReads = [household, members, vehicles, contacts]
  const denied = readsHousehold && householdReads.every(is403)
  const deniedCode = isApiError(household.error) ? household.error.code : null
  const deniedText = isApiError(household.error) ? household.error.message : null
  const anyCached = !!cachedMe || !!cachedHousehold || !!memberItems || !!vehicleItems || !!contactItems
  const staleness = denied ? null : stalenessOf({ has: anyCached, online, failures: queries.filter((q) => !!q.error).map(failureOf) })

  return {
    fixture: null,
    hero: { name, initials: initialsOf(name), place, role: roleTag(resident) },
    photo,
    details: {
      status: statusOf(!!cachedMe, me),
      message: is403(me) ? deniedSentence(isApiError(me.error) ? me.error.code : null) : sentenceOf(me, 'your details are'),
      linked: !!resident,
      email: shown(showing.email, resident?.email),
      phone: shown(showing.phone, resident?.phone),
      change: change ? { opId: change.opId, state: change.state, refusal: change.refusal } : null,
    },
    members: { status: statusOf(!!memberItems, members), rows: memberRows(memberItems ?? [], ops), message: sentenceOf(members, 'your household is') },
    membersFetchedAt: members.fulfilledTimeStamp,
    manage: { ...manageBase, allowed: canManageMembers(cachedHousehold?.can_manage_members, resident), selfId: resident?.id ?? null },
    vehicles: { status: statusOf(!!vehicleItems, vehicles), rows: vehicleRows(vehicleItems ?? [], ops), message: sentenceOf(vehicles, 'your vehicles are') },
    // Read with the household (`me.household.pets`), so the tab asks for nothing more; a server older than this sends none.
    pets: { status: statusOf(!!cachedHousehold, household), rows: petRows(cachedHousehold?.pets ?? [], ops), message: sentenceOf(household, 'your pets are') },
    contacts: { status: statusOf(!!contactItems, contacts), rows: contactRows(contactItems ?? [], ops), message: sentenceOf(contacts, 'your emergency contacts are') },
    mayEdit,
    denied,
    deniedMessage: deniedSentence(deniedCode, deniedText),
    keptBy: readsHousehold ? null : keptBySentence(savedMe?.access),
    staleNote: staleSentence(staleness, 'household'),
    waiting: waitingCount(ops),
    refreshing,
    refresh,
    dismiss,
    signOut,
  }
}
