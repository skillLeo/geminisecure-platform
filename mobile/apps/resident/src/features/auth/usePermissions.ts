/**
 * resident-app-29 · Permissions — asking the phone, honestly, and never holding the app hostage.
 *
 * SAFETY NEVER DEPENDS ON CONSENT. Every permission here makes something better — a panic alert
 * that carries a position, a visitor alert that arrives when the app is closed, a photo on a
 * report — and none of them is required: "Continue" always continues, and a refusal is shown
 * as a fact with a way to change it (the phone's Settings), never as an error.
 *
 * Each row knows the phone's REAL answer: not yet asked · allowed · not allowed (can ask again)
 * · blocked (only Settings can change it) · not available here (said plainly — e.g. remote
 * notifications inside Expo Go on Android, which needs the installed app). The board draws no
 * state, so the screen draws none: a screen reader hears it, and a tap acts on it.
 */

import { useCallback, useEffect, useState } from 'react'
import { Linking, Platform } from 'react-native'
import { Camera } from 'expo-camera'
import * as Location from 'expo-location'
import { getStorage, isExpoGo, isFidelity, jsonStore } from '@gs/core'
import { notificationPermission } from '@gs/core/notify'
import { permissionsFixture } from '@/fidelity/auth'

export type PermissionKey = 'notifications' | 'location' | 'camera'
export type PermissionState = 'unknown' | 'granted' | 'denied' | 'blocked' | 'unavailable'
type Answer = { granted: boolean; canAskAgain: boolean; status?: string }

const askedBox = () => jsonStore<{ at: string }>(getStorage(), 'pref:permissions_asked', 'v1')

/*
 * EXPLAINED ONCE PER PHONE (M47 §3, src/once/once.ts). This screen asks THIS phone for its permissions, so it is done
 * once the phone has answered each of them — an answer the phone itself keeps through every sign-out and drops, rightly,
 * with a reinstall. "Continue" pressed here counts too (on a phone that cannot answer, say). The flag on its own was in
 * the key-value store that every sign-out wipes, so the screen came back after every sign-in.
 */
let phoneAnswered = false
let checked = false

export const permissionsAsked = {
  get: (): boolean => phoneAnswered || askedBox().get() !== null,
  mark: () => askedBox().set({ at: new Date().toISOString() }),
  /** Has the phone been asked what it has answered, this launch? */
  known: (): boolean => checked || isFidelity,
  /** Ask the phone — without asking the person anything — whether every permission here already has its answer. */
  async hydrate(): Promise<void> {
    if (isFidelity) return
    try {
      const states = await Promise.all((['notifications', 'location', 'camera'] as const).map((key) => read(key, false)))
      phoneAnswered = states.every((state) => state !== 'unknown')
    } finally {
      checked = true
    }
  },
}

const toState = (a: Answer): PermissionState => (a.granted ? 'granted' : a.status === 'undetermined' ? 'unknown' : a.canAskAgain ? 'denied' : 'blocked')

/**
 * The phone's answer comes through the ONE door in @gs/core/notify. The package itself is never imported
 * here: inside Expo Go on Android its index throws at import (its push half is gone), which stops the app.
 */
type NotificationsModule = { getPermissionsAsync: () => Promise<Answer>; requestPermissionsAsync: () => Promise<Answer> }
function notifications(): NotificationsModule | null {
  if (Platform.OS === 'web') return null
  const answer = async (ask: boolean): Promise<Answer> => {
    const p = await notificationPermission(ask)
    if (!p) throw new Error('the phone gave no answer')
    return p
  }
  return { getPermissionsAsync: () => answer(false), requestPermissionsAsync: () => answer(true) }
}

async function read(key: PermissionKey, ask: boolean): Promise<PermissionState> {
  try {
    if (key === 'notifications') {
      const mod = notifications()
      if (!mod) return 'unavailable'
      return toState(ask ? await mod.requestPermissionsAsync() : await mod.getPermissionsAsync())
    }
    if (key === 'location') return toState(ask ? await Location.requestForegroundPermissionsAsync() : await Location.getForegroundPermissionsAsync())
    // The camera ONLY. The microphone is not asked for here: speaking a request (M12 §2.9, D-180) asks for it itself, the
    // first time the resident taps "Speak", with its declared purpose — so nobody is asked for a microphone they never use.
    return toState(ask ? await Camera.requestCameraPermissionsAsync() : await Camera.getCameraPermissionsAsync())
  } catch {
    return 'unavailable'
  }
}

export const STATE_LABEL: Record<PermissionState, string> = {
  unknown: 'Not asked yet: tap to allow',
  granted: 'Allowed',
  denied: 'Not allowed: tap to ask again',
  blocked: "Blocked in the phone's Settings: tap to open them",
  unavailable: 'Not available on this device',
}

export type PermissionsModel = {
  states: Record<PermissionKey, PermissionState>
  /** Extra honesty for a row, beyond its state. */
  notes: Partial<Record<PermissionKey, string>>
  showState: boolean
  /** A row's line as the BOARD words it — set in fidelity mode only, for the measurement; a phone shows the screen's own. */
  why: Partial<Record<PermissionKey, string>>
  busy: PermissionKey | 'all' | null
  ask: (key: PermissionKey) => void
  continueOn: () => void
}

export function usePermissions(onDone: () => void): PermissionsModel {
  const board = isFidelity ? permissionsFixture : null
  const [states, setStates] = useState<Record<PermissionKey, PermissionState>>({ notifications: 'unknown', location: 'unknown', camera: 'unknown' })
  const [busy, setBusy] = useState<PermissionsModel['busy']>(null)

  useEffect(() => {
    if (isFidelity) return
    let alive = true
    void Promise.all((['notifications', 'location', 'camera'] as const).map(async (key) => [key, await read(key, false)] as const)).then((pairs) => {
      if (alive) setStates(Object.fromEntries(pairs) as Record<PermissionKey, PermissionState>)
    })
    return () => {
      alive = false
    }
  }, [])

  const ask = useCallback(
    async (key: PermissionKey) => {
      if (isFidelity || busy) return
      if (states[key] === 'blocked') return void Linking.openSettings()
      if (states[key] === 'granted' || states[key] === 'unavailable') return
      setBusy(key)
      try {
        const next = await read(key, true)
        setStates((s) => ({ ...s, [key]: next }))
      } finally {
        setBusy(null)
      }
    },
    [busy, states],
  )

  // "Continue" asks for whatever has not been asked yet, one at a time, and then continues WHATEVER the answers were.
  const continueOn = useCallback(async () => {
    if (isFidelity || busy) return
    setBusy('all')
    try {
      for (const key of ['notifications', 'location', 'camera'] as const) {
        if (states[key] !== 'unknown') continue
        const next = await read(key, true)
        setStates((s) => ({ ...s, [key]: next }))
      }
    } finally {
      permissionsAsked.mark()
      setBusy(null)
      onDone()
    }
  }, [busy, states, onDone])

  return {
    states,
    notes: {
      ...(isExpoGo && Platform.OS === 'android' ? { notifications: 'In this preview app, alerts only show while GeminiSecure is open. The installed app receives them any time.' } : null),
      ...(Platform.OS === 'web' ? { notifications: 'Notifications are set up on the phone app, not in a browser.' } : null),
    },
    showState: board ? board.showState : true,
    why: board ? board.why : {},
    busy,
    ask: (key) => void ask(key),
    continueOn: () => void continueOn(),
  }
}
