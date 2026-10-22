/**
 * THE WORDS OF THE PROFILE TAB'S TWO QUESTIONS — removing a member, cancelling their invite — as pure functions,
 * so what the ConfirmSheet says is tested without a phone.
 *
 * The user's report (24 September): the old card read "Remove Soft Dev?" with buttons "Remove Soft" / "Keep Soft" —
 * a first name, on a red button, beside a whole household list. Every word here names the person IN FULL, and the
 * sentences under the title are household.ts's own (`removeSentence`, `cancelInviteSentence`), said BEFORE it is done.
 */

import { sameWindow, windowLine, windowProblem, type HelperWindow } from './helperHours'
import { REMOVE_OFFLINE, cancelInviteSentence, isLevel, levelLabel, lostWith, narrowing, removeSentence, type Level, type Member } from './household'

export type SheetWords = { title: string; lines: string[]; confirmLabel: string; cancelLabel: string; blocked: string | null }

/**
 * NO ANSWER IS NOT "NO CONNECTION". A delete that timed out, or whose connection broke on the way, may well have gone
 * through: the phone cannot tell, so it says that — and how to find out — never that it needs a connection.
 */
export const REMOVE_NO_ANSWER = 'No answer came back, so this phone cannot tell whether they were removed. Pull down to refresh your household and check before you try again.'

/** Said about CANCELLING, in the sheet that asks it — not the sentence about sending an invite. */
export const CANCEL_INVITE_OFFLINE = 'Cancelling an invite needs a connection: it is never saved to do later.'
export const CANCEL_INVITE_NO_ANSWER = 'No answer came back, so this phone cannot tell whether the invite was cancelled. Pull down to refresh your household and check.'

/** Removing a contact, a vehicle or a pet is online only: it changes the household's list at once. */
export const ITEM_OFFLINE = 'Removing needs a connection: it takes effect at once, so it is never saved to do later.'
export const itemNoAnswer = (what: 'contact' | 'vehicle' | 'pet'): string =>
  `No answer came back, so this phone cannot tell whether the ${what} was removed. Pull down to refresh and check before you try again.`

/** The name as the register holds it, tidied — or "this person" (never "them" on a button). */
const whole = (name: string | null | undefined): string => (name ?? '').trim().replace(/\s+/g, ' ') || 'this person'

/** "Remove Soft Dev from your household?" — online only: offline the button is disabled with the reason. */
export function removeSheet(member: Pick<Member, 'full_name'>, online: boolean): SheetWords {
  const name = whole(member.full_name)
  return {
    title: `Remove ${name} from your household?`,
    lines: [removeSentence(member.full_name)],
    confirmLabel: `Remove ${name}`,
    cancelLabel: 'Cancel',
    blocked: online ? null : REMOVE_OFFLINE,
  }
}

/**
 * "Remove Marcia Ahmed from your emergency contacts?" The copy says what really changes: the panic screen stops
 * offering them, and the estate's panic text (once a provider is connected) stops going to them.
 */
export function removeContactSheet(contact: { name: string | null | undefined }, online: boolean): SheetWords {
  const name = whole(contact.name)
  return {
    title: `Remove ${name} from your emergency contacts?`,
    lines: [`${name} is taken off your household's emergency contacts at once: your panic screen stops offering to text or call them, and no emergency text goes to them. You can add them again at any time.`],
    confirmLabel: `Remove ${name}`,
    cancelLabel: 'Cancel',
    blocked: online ? null : ITEM_OFFLINE,
  }
}

/** "Remove Silver Toyota Corolla · PPV 4471 from your vehicles?" No gate decision reads these plates, and it says so. */
export function removeVehicleSheet(vehicle: { line: string }, online: boolean): SheetWords {
  const line = vehicle.line.trim().replace(/\s+/g, ' ') || 'this vehicle'
  return {
    title: `Remove ${line} from your vehicles?`,
    lines: ["It leaves your household's list at once. The gate does not decide who enters from this list, so nobody's entry changes. You can add it again at any time."],
    confirmLabel: 'Remove the vehicle',
    cancelLabel: 'Cancel',
    blocked: online ? null : ITEM_OFFLINE,
  }
}

/** "Remove Bella · Dog from your pets?" (M50 §5.3c) — asked from inside the pet's own screen, never on the row. */
export function removePetSheet(pet: { line: string }, online: boolean): SheetWords {
  const line = pet.line.trim().replace(/\s+/g, ' ') || 'this pet'
  return {
    title: `Remove ${line} from your pets?`,
    lines: ["It leaves your household's list at once. You can add it again at any time."],
    confirmLabel: 'Remove the pet',
    cancelLabel: 'Cancel',
    blocked: online ? null : ITEM_OFFLINE,
  }
}

/** Changing a level is online only: it takes effect on the member's next request. */
export const LEVEL_OFFLINE_SHEET = "Changing someone's access needs a connection. It takes effect at once, so it is never saved to do later."

/**
 * "Change Soft Dev to Dependent?" — what they LOSE at once when it narrows (a destructive act, said as such), what
 * they gain when it widens. Online only. Choosing their current level again is no change, and is not offered.
 */
export function levelSheet(member: Pick<Member, 'full_name'> & { access_level?: string | null }, to: Level, online: boolean): SheetWords & { destructive: boolean } {
  const name = whole(member.full_name)
  const current = isLevel(member.access_level) ? member.access_level : 'member'
  const label = levelLabel(to)
  const same = current === to
  return {
    title: same ? `${name}'s access` : `Change ${name} to ${label}?`,
    lines: same ? [`${name} has ${levelLabel(current)} access now. Choose another level to change it.`] : [lostWith(to), 'It takes effect on their next request in the app. Nobody is signed out.'],
    confirmLabel: same ? `Keep ${label}` : `Change to ${label}`,
    cancelLabel: same ? 'Close' : `Keep ${levelLabel(current)}`,
    blocked: same ? `${name} already has ${label} access.` : online ? null : LEVEL_OFFLINE_SHEET,
    destructive: !same && narrowing(current, to),
  }
}

/** A helper's days and hours are online only: the gate must hear a pause at once. */
export const HOURS_OFFLINE_SHEET = "Changing a helper's days and hours needs a connection. It takes effect at once, so it is never saved to do later."

/**
 * "Marcia Brown's days and hours" (the user, 8 Oct 2026) — when the primary resident's Helper may come, in the words
 * the server will hold. Pausing is the destructive one: their e-Pass is cancelled at once and every gate refuses it.
 * Nothing changed yet is nothing to save; a window the server would refuse says why before it is sent.
 */
export function hoursSheet(member: Pick<Member, 'full_name'>, draft: HelperWindow, saved: HelperWindow | null, online: boolean): SheetWords & { destructive: boolean } {
  const name = whole(member.full_name)
  const unchanged = !!saved && sameWindow(draft, saved)
  const pausing = draft.paused && !saved?.paused
  const resuming = !draft.paused && !!saved?.paused
  return {
    title: `${name}'s days and hours`,
    lines: draft.paused
      ? [`Paused: ${name}'s e-Pass is cancelled at once and every gate refuses it, until you resume them.`]
      : [windowLine(draft), 'Their e-Pass opens the gate only then: on their phone and at every gate. It takes effect at once.'],
    confirmLabel: pausing ? `Pause ${name}` : resuming ? `Resume ${name}` : 'Save days and hours',
    cancelLabel: unchanged ? 'Close' : 'Cancel',
    blocked: unchanged ? 'Nothing has changed yet.' : (windowProblem(draft) ?? (online ? null : HOURS_OFFLINE_SHEET)),
    destructive: pausing,
  }
}

/** "Cancel Soft Dev's invite?" — the act is itself "cancel", so the way out is "Keep the invite". */
export function cancelInviteSheet(member: Pick<Member, 'full_name'>, online: boolean): SheetWords {
  const name = whole(member.full_name)
  return {
    title: `Cancel ${name}'s invite?`,
    lines: [cancelInviteSentence(member.full_name)],
    confirmLabel: 'Cancel the invite',
    cancelLabel: 'Keep the invite',
    blocked: online ? null : CANCEL_INVITE_OFFLINE,
  }
}
