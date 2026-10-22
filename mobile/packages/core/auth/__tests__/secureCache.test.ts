import * as SecureStore from 'expo-secure-store'
import AsyncStorage from '@react-native-async-storage/async-storage'
import { secureCache } from '../secureCache'

const SLIP = { id: 41, period_label: 'August 2026', gross: '85721.00', net: '79564.09' }

describe('secureCache: what may be kept on the phone but never in shared storage', () => {
  beforeEach(async () => {
    await secureCache.clear()
    jest.clearAllMocks()
  })

  it('writes to the secure store, this device only, and to nothing else', async () => {
    const shared = jest.spyOn(AsyncStorage, 'setItem')
    await secureCache.set('payslip.41', 'GS-9001', SLIP, '2026-09-04T14:41:00Z')
    const calls = (SecureStore.setItemAsync as jest.Mock).mock.calls
    expect(calls.length).toBeGreaterThan(0)
    for (const [key, , options] of calls) {
      expect(String(key)).toMatch(/^gs\.sc\./)
      expect(options).toEqual({ keychainAccessible: 'WHEN_UNLOCKED_THIS_DEVICE_ONLY' })
    }
    expect(shared).not.toHaveBeenCalled()
  })

  it('hands an entry back to its owner, with when it was saved', async () => {
    await secureCache.set('payslip.41', 'GS-9001', SLIP, '2026-09-04T14:41:00Z')
    expect(await secureCache.get('payslip.41', 'GS-9001')).toEqual({ value: SLIP, savedAt: '2026-09-04T14:41:00Z' })
  })

  it("never hands one guard's entry to another", async () => {
    await secureCache.set('payslip.41', 'GS-9001', SLIP, '2026-09-04T14:41:00Z')
    expect(await secureCache.get('payslip.41', 'GS-1041')).toBeNull()
  })

  it('an unreadable entry is nothing, not a crash', async () => {
    await SecureStore.setItemAsync('gs.sc.payslip.9', '{not json')
    expect(await secureCache.get('payslip.9', 'GS-9001')).toBeNull()
  })

  it('clear removes every entry and the index: logout leaves nothing behind', async () => {
    await secureCache.set('payslips.index', 'GS-9001', [41, 40], '2026-09-04T14:41:00Z')
    await secureCache.set('payslip.41', 'GS-9001', SLIP, '2026-09-04T14:41:00Z')
    await secureCache.set('payslip.40', 'GS-9001', SLIP, '2026-09-04T14:41:00Z')
    await secureCache.clear()
    expect(await secureCache.get('payslip.41', 'GS-9001')).toBeNull()
    expect(await secureCache.get('payslip.40', 'GS-9001')).toBeNull()
    expect(await secureCache.get('payslips.index', 'GS-9001')).toBeNull()
    expect(await SecureStore.getItemAsync('gs.sc.index')).toBeNull()
  })

  it('a name is made safe for the Keychain', async () => {
    await secureCache.set('payslip/41 x', 'GS-9001', SLIP, '2026-09-04T14:41:00Z')
    const keys = (SecureStore.setItemAsync as jest.Mock).mock.calls.map(([key]) => String(key))
    expect(keys).toContain('gs.sc.payslip_41_x')
  })
})
