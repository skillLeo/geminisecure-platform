/**
 * THE INSTALL'S ID, WITHOUT THE GUARD APP'S CRYPTOGRAPHY (M11 A4).
 *
 * `deviceUid` moved into its own module so the Resident App can have it without tweetnacl and the pass
 * verifier. Moving it must not change a single installed phone: the id is read from and written to the
 * SAME secure-store key as before, so every handset keeps the id the server already bound it to.
 */

import * as SecureStore from 'expo-secure-store'
import { deviceUid } from '../deviceUid'
import * as index from '../index'

const HISTORICAL_KEY = 'gs.guard.device_uid'

describe('deviceUid', () => {
  it('is made once and kept, under the key every installed phone already uses', async () => {
    const first = await deviceUid()
    expect(first).toMatch(/^[0-9a-f-]{36}$/)
    expect(await SecureStore.getItemAsync(HISTORICAL_KEY)).toBe(first)
    expect(await deviceUid()).toBe(first)
  })

  it('an id a phone already holds is the id it gets back', async () => {
    await SecureStore.setItemAsync(HISTORICAL_KEY, 'already-bound-id')
    expect(await deviceUid()).toBe('already-bound-id')
  })

  it('the crypto index hands out the very same function the Guard App always imported', () => {
    expect(index.deviceUid).toBe(deviceUid)
    expect(typeof index.devicePublicKey).toBe('function')
    expect(typeof index.verifyPassOffline).toBe('function')
  })

  it('loads no tweetnacl and no pass verifier', () => {
    jest.isolateModules(() => {
      jest.doMock('tweetnacl', () => {
        throw new Error('tweetnacl was loaded')
      })
      jest.doMock('../passVerifier', () => {
        throw new Error('the pass verifier was loaded')
      })
      expect(() => require('../deviceUid')).not.toThrow()
    })
  })
})
