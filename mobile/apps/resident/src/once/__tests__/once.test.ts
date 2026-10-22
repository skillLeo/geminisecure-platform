/**
 * SHOWN ONCE (M47 §3) — the rule in one place: the account's record decides when the server keeps one, this phone's
 * record when it does not, and nothing is decided before the account has answered.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'
import { accountShown, onceVerdict, tellAccount } from '../once'
import { PHONE_CHOICES } from '../../store/phoneChoices'

const SRC = path.join(__dirname, '..', '..')
const AT = '2026-10-07T14:02:11+00:00'

describe('the account\'s record', () => {
  it('is read from me.show, and is null for a server that keeps none', () => {
    expect(accountShown({ account: { shown: { tour: AT } } } as never)).toEqual({ tour: AT })
    expect(accountShown({ account: { shown: { tour: null } } } as never)).toEqual({ tour: null })
    expect(accountShown({ account: {} } as never)).toBeNull()
    expect(accountShown(undefined)).toBeNull()
  })

  it('decides when the server keeps it: done on the account, or seen here and not yet told', () => {
    expect(onceVerdict({ key: 'tour', record: { tour: AT }, answered: true, seenHere: false })).toBe('done')
    expect(onceVerdict({ key: 'tour', record: { tour: null }, answered: true, seenHere: true })).toBe('done')
    expect(onceVerdict({ key: 'tour', record: { tour: null }, answered: true, seenHere: false })).toBe('show')
  })

  it('leaves it to this phone on an older server, and waits until the account has answered', () => {
    expect(onceVerdict({ key: 'tour', record: null, answered: true, seenHere: true })).toBe('done')
    expect(onceVerdict({ key: 'tour', record: null, answered: true, seenHere: false })).toBe('show')
    expect(onceVerdict({ key: 'tour', record: null, answered: false, seenHere: false })).toBe('wait')
  })

  it('tells the account only what it does not know yet', () => {
    expect(tellAccount({ key: 'tour', record: { tour: null }, seenHere: true })).toBe(true)
    expect(tellAccount({ key: 'tour', record: { tour: AT }, seenHere: true })).toBe(false)
    expect(tellAccount({ key: 'tour', record: null, seenHere: true })).toBe(false)
    expect(tellAccount({ key: 'tour', record: { tour: null }, seenHere: false })).toBe(false)
  })
})

describe('what this phone keeps through a sign-out nobody chose', () => {
  it('names each module\'s own key', () => {
    const writes: Record<string, string> = {
      'v1:settings.prefs': 'features/settings/prefs.ts',
      'v1:notifications.prefs': 'features/notify/prefs.ts',
      'v1:pref:last_estate': 'features/auth/session.ts',
      'v1:pref:permissions_asked': 'features/auth/usePermissions.ts',
    }
    for (const key of PHONE_CHOICES) {
      const [version, name] = [key.slice(0, key.indexOf(':')), key.slice(key.indexOf(':') + 1)]
      const file = key === 'v1:prefs.dataLight' ? path.join(SRC, '..', '..', '..', 'packages', 'core', 'lib', 'dataLight.ts') : path.join(SRC, writes[key]!)
      const source = readFileSync(file, 'utf8')
      // `jsonStore(getStorage(), 'name', 'v1')`, or the module's own KEY / VERSION constants.
      expect(source.includes(`'${name}', '${version}'`) || (source.includes(`KEY = '${name}'`) && source.includes(`VERSION = '${version}'`))).toBe(true)
    }
  })

  it('is on the store\'s list for a session that ends by itself', () => {
    expect(readFileSync(path.join(SRC, 'store', 'index.ts'), 'utf8')).toMatch(/clearEverything\('resident', store\.dispatch, reason, \[[^\]]*\.\.\.PHONE_CHOICES\]\)/)
  })
})
