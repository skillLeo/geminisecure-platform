/**
 * THE DURESS PATH, PROVEN SILENT.
 *
 * A guard raising a silent duress is being watched. This test stands where the coercer stands and
 * checks every channel a phone has for telling a bystander that something just happened:
 *
 *   a vibration · a sound · a navigation · a dialog or toast · a badge or count · a row in a list
 *
 * — and then checks the other half: dispatch DID get it, exactly once, marked silent.
 */
import { readFileSync } from 'node:fs'
import path from 'node:path'

const mockHaptics = { notificationAsync: jest.fn(async () => undefined), impactAsync: jest.fn(async () => undefined), selectionAsync: jest.fn(async () => undefined) }
jest.mock('expo-haptics', () => ({ ...mockHaptics, NotificationFeedbackType: { Warning: 'warning', Success: 'success', Error: 'error' }, ImpactFeedbackStyle: { Heavy: 'heavy', Medium: 'medium', Light: 'light' } }))
jest.mock('expo-location', () => ({ getForegroundPermissionsAsync: jest.fn(async () => ({ granted: false })), getLastKnownPositionAsync: jest.fn(), getCurrentPositionAsync: jest.fn(), Accuracy: { Balanced: 3 } }))

const mockRouter = { push: jest.fn(), replace: jest.fn(), navigate: jest.fn(), back: jest.fn() }
jest.mock('expo-router', () => ({ router: mockRouter }))

const mockAnswer = { id: 501, status: 'open', cancellable_until: '2026-09-04T14:41:12Z', acknowledged_at: null, cancelled_at: null, server_time: '2026-09-04T14:41:02Z' }
const mockSent: Record<string, unknown>[] = []
jest.mock('@gs/core/api/guard', () => ({
  guardApi: {
    endpoints: { duressStore: { initiate: jest.fn((arg: Record<string, unknown>) => ({ type: 'duressStore', arg })) } },
    useDuressShowQuery: jest.fn(() => ({ data: undefined })),
    useDuressCancelMutation: jest.fn(() => [jest.fn()]),
  },
}))
jest.mock('@/store', () => ({
  store: {
    getState: () => ({ network: { online: true }, session: { principal: { reference: 'GS-9001' } } }),
    dispatch: jest.fn(async (action: { arg: Record<string, unknown> }) => { mockSent.push(action.arg); return { data: mockAnswer } }),
  },
}))

const mockEnqueued: { kind: string; label: string; endpoint: string; body: unknown }[] = []
jest.mock('@/offline/runtime', () => ({
  outbox: {
    enqueue: jest.fn(async (input: { kind: string; label: string; endpoint: string; body: unknown }) => { mockEnqueued.push(input); return { id: 'op-1', ...input } }),
    settle: jest.fn(async () => undefined),
    all: () => [],
  },
}))

import { Alert, Vibration } from 'react-native'
import { outboxChanged, outboxReducer, SILENT_KIND, type OutboxOp } from '@gs/core/offline'
import { raiseDuress } from '../useDuress'

const alertSpy = jest.spyOn(Alert, 'alert').mockImplementation(() => undefined)
const vibrateSpy = jest.spyOn(Vibration, 'vibrate').mockImplementation(() => undefined)

describe('a SILENT duress, seen from beside the guard', () => {
  beforeAll(async () => {
    await raiseDuress('silent')
  })

  it('makes no vibration and no sound', () => {
    expect(mockHaptics.notificationAsync).not.toHaveBeenCalled()
    expect(mockHaptics.impactAsync).not.toHaveBeenCalled()
    expect(mockHaptics.selectionAsync).not.toHaveBeenCalled()
    expect(vibrateSpy).not.toHaveBeenCalled()
  })

  it('goes nowhere and shows nothing: no navigation, no dialog', () => {
    for (const go of Object.values(mockRouter)) expect(go).not.toHaveBeenCalled()
    expect(alertSpy).not.toHaveBeenCalled()
  })

  it('leaves no badge, no count and no row: the queue’s mirror never holds it', () => {
    expect(mockEnqueued).toHaveLength(1)
    expect(mockEnqueued[0]?.kind).toBe(SILENT_KIND)
    const held = { id: 'op-1', endpoint: 'duress.store', label: 'Duress alert', kind: SILENT_KIND, state: 'pending', attempts: 0, capturedAt: '2026-09-04T09:41:02-05:00', lastTriedAt: null, error: null } as unknown as OutboxOp
    const scan = { ...held, id: 'op-2', endpoint: 'patrol.scan', label: 'Checkpoint scan: Pool Deck', kind: 'patrol' } as OutboxOp
    const ui = outboxReducer(undefined, outboxChanged([held, scan]))
    expect(ui.waiting).toBe(1) // the scan — not the alert
    expect(ui.ops.map((o) => o.label)).toEqual(['Checkpoint scan: Pool Deck'])
    expect(JSON.stringify(ui)).not.toMatch(/duress/i)
  })

  it('…and dispatch DID get it: sent once, directly, marked silent, captured on disk first', () => {
    expect(mockSent).toHaveLength(1)
    expect(mockSent[0]).toMatchObject({ mode: 'silent' })
    expect(mockEnqueued[0]).toMatchObject({ endpoint: 'duress.store', body: { mode: 'silent' } })
  })

  it('a second press while one is active does nothing at all', async () => {
    await raiseDuress('silent')
    await raiseDuress('audible')
    expect(mockSent).toHaveLength(1)
    expect(mockHaptics.notificationAsync).not.toHaveBeenCalled()
  })
})

describe('the source holds the line', () => {
  const source = (file: string) => readFileSync(path.join(__dirname, '..', file), 'utf8')

  it('the raising code can reach no navigator, no dialog, no toast and no sound player', () => {
    const code = source('useDuress.ts').replace(/\/\*[\s\S]*?\*\/|\/\/.*$/gm, '')
    expect(code).not.toMatch(/expo-router|Alert\.|ToastAndroid|expo-av|expo-audio|Vibration|expo-notifications/)
    // The only haptic in the file is behind the audible branch.
    expect(code.match(/Haptics\.\w+\(/g)).toEqual(['Haptics.notificationAsync('])
    expect(code).toMatch(/if \(mode === 'audible'\) void Haptics\.notificationAsync/)
  })

  it('Home’s silent trigger does nothing but raise it', () => {
    const home = readFileSync(path.join(__dirname, '..', '..', 'dashboard', 'screens', 'DashboardScreen.tsx'), 'utf8')
    expect(home).toMatch(/const silentDuress = \(\) => void raiseDuress\('silent'\)/)
    expect(home).toMatch(/onLongPress=\{silentDuress\} delayLongPress=\{SILENT_HOLD_MS\}/)
  })
})
