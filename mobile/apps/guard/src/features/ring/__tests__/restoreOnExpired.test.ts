/**
 * G4: an expired session must not wipe a live ring. The guard store clears the tray on every 401 — which cancels the
 * call-style ring too — so on `expired`, and only then, it asks the ring to put back what is still live.
 */

const mockOrder: string[] = []
let mockHooks: { onSessionRevoked: (reason: 'expired' | 'revoked') => Promise<void> } | null = null

jest.mock('@gs/core/api', () => ({
  configureApi: (hooks: typeof mockHooks) => { mockHooks = hooks },
  coreReducers: {},
  coreMiddleware: () => (next: (a: unknown) => unknown) => (action: unknown) => next(action),
  startCoreListeners: () => undefined,
}))
jest.mock('@gs/core/auth', () => ({ clearEverything: jest.fn(async () => { mockOrder.push('clearEverything') }) }))
jest.mock('@gs/core/notify', () => ({ clearNotifications: jest.fn(async () => { mockOrder.push('clearNotifications') }) }))
jest.mock('@gs/core/offline', () => ({ networkReducer: (s = { online: true }) => s, outboxReducer: (s = { ops: [] }) => s }))
jest.mock('../native', () => ({ GuardRing: { restore: jest.fn(async () => { mockOrder.push('restore'); return 1 }) } }))

const restore = (jest.requireMock('../native') as { GuardRing: { restore: jest.Mock } }).GuardRing.restore

beforeAll(() => {
  // Required here, not imported: an import is hoisted above the `let` it hands its hooks to.
  require('@/store')
})

beforeEach(() => {
  mockOrder.length = 0
  restore.mockClear()
})

describe('the tray is cleared at a 401, and a live ring is put back when the session merely expired', () => {
  it('expired: the tray is cleared, THEN the ring puts back what is live', async () => {
    await mockHooks!.onSessionRevoked('expired')
    expect(mockOrder).toEqual(['clearNotifications', 'restore', 'clearEverything'])
  })

  it('revoked: the full clear, and nothing is put back', async () => {
    await mockHooks!.onSessionRevoked('revoked')
    expect(restore).not.toHaveBeenCalled()
    expect(mockOrder).toEqual(['clearNotifications', 'clearEverything'])
  })

  it('a ring that cannot be put back never stops the session ending', async () => {
    restore.mockRejectedValueOnce(new Error('no module'))
    await expect(mockHooks!.onSessionRevoked('expired')).resolves.toBeUndefined()
    expect(mockOrder).toContain('clearEverything')
  })
})
