/**
 * WHAT KIND OF EMERGENCY — the resident's one tap, if they can (M10 §1). Pure, so it can be proven. The one choice in
 * the product that is never required (DECISIONS D-M18C-PANIC-TYPE): nothing may stand between a press and the alert.
 *
 * "Panic type: let the resident pick if they can — medical, fire, security, other — but never require
 * it. An unlabelled panic goes to everyone exactly the same." The client's reason is the whole design:
 * "When people are in a panic they don't remember to do the things they're supposed to do."
 *
 * So the choice sits beside the button and never in front of it. Nothing is selected to begin with, the
 * hold works the same whether or not anything is, and a chosen type is simply sent with the alert
 * (`raiseAlert`'s `type`). It is chosen BEFORE the press and never after: the server has no way to
 * relabel an alert, and a question put to somebody who has just pressed a panic button is a delay.
 *
 * A LABEL IS NOT A SERVICE. "Medical" tells the community's responders what kind of emergency it is. It
 * does not call an ambulance, and "Fire" does not call the fire service; the screen says so the moment
 * either is chosen, with 110 one tap away beside it.
 */

import type { AlertType } from '@gs/core/safety'

export const PANIC_TYPES: readonly { value: AlertType; label: string }[] = [
  { value: 'medical', label: 'Medical' },
  { value: 'fire', label: 'Fire' },
  { value: 'security', label: 'Security' },
  { value: 'other', label: 'Other' },
]

/** Jamaica: fire and ambulance. 119 (police) stays the screen's main number. */
export const AMBULANCE_FIRE_NUMBER = '110'

/** Whether this type is one the police number is the wrong call for. */
export const needsAmbulanceOrFire = (type: AlertType | null | undefined): boolean => type === 'medical' || type === 'fire'

/** A second tap on the chosen type clears it: the choice is never a trap. */
export const toggleType = (current: AlertType | null, tapped: AlertType): AlertType | null => (current === tapped ? null : tapped)

/** The line under the choices, which changes with it. */
export function typeHint(type: AlertType | null): string {
  if (needsAmbulanceOrFire(type)) return `This tells your community’s responders what kind it is. It does not call an ambulance or the fire service: for those, call ${AMBULANCE_FIRE_NUMBER}.`
  if (type) return 'The alert goes to the same people either way. Tap it again to clear it.'
  // Never "Optional" (M18C §1 — the word appears nowhere): it says what choosing does, which is the point.
  return 'Choose one if you can. The alert goes to the same people whether you choose or not.'
}

const LABEL: Record<AlertType, string> = {
  medical: 'Medical emergency',
  fire: 'Fire emergency',
  security: 'Security emergency',
  other: 'Other emergency',
}

/**
 * The hero's line after the press: what kind of emergency this alert IS.
 *
 * Once the server has answered, its `kind` decides for medical and fire — that is what the control room
 * sees. `security` and `other` are a plain `panic` there by design, so for those the resident's own word
 * is kept. A server that answered `panic` to a "medical" press (one that predates the field) is shown as
 * what it recorded: a general emergency. Before any answer, it is what was pressed.
 */
export function alertLabel(chosen: AlertType | null | undefined, serverKind?: string | null): string {
  if (serverKind === 'medical' || serverKind === 'fire') return LABEL[serverKind]
  if (serverKind === 'panic') return chosen === 'security' || chosen === 'other' ? LABEL[chosen] : 'General emergency'
  return (chosen ? LABEL[chosen] : undefined) ?? 'General emergency'
}
