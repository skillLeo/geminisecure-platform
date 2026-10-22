import * as SecureStore from 'expo-secure-store'
import { claimApproved, noSession, sessionReducer, sessionRestored, sessionRevoked, signedOut, type SessionState } from '../sessionSlice'
import { tokenStorage } from '../tokenStorage'

const marcus = { id: 7, name: 'Marcus Whyte', siteName: 'Phoenix Park Village 1', reference: 'GS-1041' }
const booting: SessionState = { status: 'booting', principal: null, endedReason: null, demo: false, reviewer: false }

describe('session slice', () => {
  it('starts booting and never holds a token', () => {
    const s = sessionReducer(undefined, { type: '@@init' })
    expect(s).toEqual(booting)
    expect(JSON.stringify(s)).not.toMatch(/token/i)
  })

  it('restores an active session', () => {
    expect(sessionReducer(booting, sessionRestored({ principal: marcus }))).toMatchObject({ status: 'active', principal: marcus })
  })

  it('a resident whose claim awaits the estate is pending, then active with no new sign-in', () => {
    const pending = sessionReducer(booting, sessionRestored({ principal: null, pending: true }))
    expect(pending.status).toBe('pending')
    expect(sessionReducer(pending, claimApproved()).status).toBe('active')
  })

  it('claimApproved does nothing to a signed-out session', () => {
    expect(sessionReducer(sessionReducer(booting, noSession()), claimApproved()).status).toBe('signedOut')
  })

  it('a 401 ends the session and says why, once', () => {
    const active = sessionReducer(booting, sessionRestored({ principal: marcus }))
    expect(sessionReducer(active, sessionRevoked())).toEqual({ status: 'signedOut', principal: null, endedReason: 'revoked', demo: false, reviewer: false })
    expect(sessionReducer(active, signedOut()).endedReason).toBe('signed_out')
  })

  /*
   * THE DEMONSTRATION FLAG. "Explore a demo community" is the only thing that sets it,
   * and every way a session can END clears it — a phone that looked round the demonstration
   * and then signed in properly must not still be wearing the banner.
   */
  it('marks a demonstration session, and never carries the mark out of it', () => {
    const demo = sessionReducer(booting, sessionRestored({ principal: marcus, demo: true }))
    expect(demo.demo).toBe(true)

    expect(sessionReducer(demo, signedOut()).demo).toBe(false)
    expect(sessionReducer(demo, noSession()).demo).toBe(false)
    expect(sessionReducer(demo, sessionRevoked()).demo).toBe(false)
    // An ordinary sign-in says nothing about demonstrations, and that means NOT one.
    expect(sessionReducer(demo, sessionRestored({ principal: marcus })).demo).toBe(false)
  })

  /*
   * THE REVIEWER FLAG IS NOT THE DEMONSTRATION FLAG, and the banner needs both.
   *
   *   demo      the READ-ONLY door. "Nothing you tap can change anything" is exactly true.
   *   reviewer  the full demonstration account, signed in with its static code. Same invented
   *             estate, but it CAN write — so it is told that instead, and telling it the
   *             read-only sentence would be a plain lie.
   */
  it('tells a reviewer\'s account apart from a read-only look round, and clears both on the way out', () => {
    const reviewer = sessionReducer(booting, sessionRestored({ principal: marcus, reviewer: true }))
    expect(reviewer.reviewer).toBe(true)
    expect(reviewer.demo).toBe(false)

    // The read-only door is a reviewer's session too, whether or not the payload said so.
    const demo = sessionReducer(booting, sessionRestored({ principal: marcus, demo: true }))
    expect(demo.demo).toBe(true)
    expect(demo.reviewer).toBe(true)

    // A real resident is neither, and every way out clears both.
    expect(sessionReducer(reviewer, sessionRestored({ principal: marcus })).reviewer).toBe(false)
    for (const end of [signedOut(), noSession(), sessionRevoked()]) {
      expect(sessionReducer(reviewer, end).reviewer).toBe(false)
    }
  })
})

describe('tokenStorage: SecureStore only', () => {
  it('writes to the secure store under the app\'s own key, this device only', async () => {
    await tokenStorage.set('guard', 'tok_guard')
    expect(SecureStore.setItemAsync).toHaveBeenCalledWith('gs.guard.token', 'tok_guard', { keychainAccessible: 'WHEN_UNLOCKED_THIS_DEVICE_ONLY' })
    expect(tokenStorage.peek()).toBe('tok_guard')
  })

  it('hydrates from the secure store', async () => {
    await tokenStorage.set('resident', 'tok_res')
    expect(await tokenStorage.hydrate('resident')).toBe('tok_res')
    expect(tokenStorage.isHydrated()).toBe(true)
  })

  it('clear removes it from memory and from the secure store', async () => {
    await tokenStorage.set('guard', 'tok_guard')
    await tokenStorage.clear('guard')
    expect(tokenStorage.peek()).toBeNull()
    expect(await tokenStorage.hydrate('guard')).toBeNull()
  })
})
