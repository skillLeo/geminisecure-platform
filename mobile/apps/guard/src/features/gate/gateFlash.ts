/**
 * WHAT THE GATE JUST RECORDED, SAID ON HOME (the client, 27 Sep: "when the guard scans the QR and admits the visitor it
 * stays on the scanning screen — it should go to the home page with a message that it was successful").
 *
 * An admission or an override takes the officer back Home, and Home says, once, what was recorded and where it went:
 * "Entry recorded — Kemar Brown", "Saved to the gate log" (or "Saved on this phone — sent when signal returns"). A denial
 * stays on the scanner, which is ready for the next person at once. Held in memory only: a message about a moment is
 * for that moment, and it goes after FLASH_MS or when tapped away.
 */

import { useSyncExternalStore } from 'react'
import type { GateOutcome } from './useGateCheck'

export const FLASH_MS = 8000

export type GateFlash = { title: string; detail: string; at: number }

let current: GateFlash | null = null
const listeners = new Set<() => void>()
const emit = () => listeners.forEach((l) => l())

export function flashFor(outcome: GateOutcome): GateFlash {
  return {
    title: `${outcome.kind === 'overridden' ? 'Override recorded' : 'Entry recorded'}: ${outcome.subject}`,
    detail: outcome.pending ? 'Saved on this phone: it is sent to the gate log by itself when signal returns.' : 'Saved to the gate log.',
    at: Date.now(),
  }
}

export function showGateFlash(outcome: GateOutcome): void {
  current = flashFor(outcome)
  emit()
  const shown = current
  setTimeout(() => {
    if (current === shown) {
      current = null
      emit()
    }
  }, FLASH_MS)
}

export function clearGateFlash(): void {
  current = null
  emit()
}

const subscribe = (listener: () => void) => {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

export const useGateFlash = (): GateFlash | null => useSyncExternalStore(subscribe, () => current, () => current)
