/**
 * The on-phone store is encrypted at rest (M11 §1.1; D-176), and an install from before encryption is encrypted in
 * place without losing its queue — even if the phone dies half-way through the first opening.
 */
import { openEncrypted } from '../storage'

type Opened = { id: string; encryptionKey?: string }

function fakes(start: Record<string, string> = {}, plainFile: Record<string, string> = {}) {
  const secure = new Map(Object.entries(start))
  const opened: Opened[] = []
  const file = new Map(Object.entries(plainFile))
  const encryptedWith: string[] = []
  const mod = {
    createMMKV: (o: Opened) => {
      opened.push(o)
      return {
        getString: (k: string) => (o.encryptionKey || encryptedWith.length === 0 ? file.get(k) : undefined),
        set: (k: string, v: string) => void file.set(k, v),
        remove: (k: string) => void file.delete(k),
        getAllKeys: () => [...file.keys()],
        clearAll: () => file.clear(),
        encrypt: (key: string) => void encryptedWith.push(key),
      }
    },
  }
  const store = {
    getItem: (k: string) => secure.get(k) ?? null,
    setItem: (k: string, v: string) => void secure.set(k, v),
    deleteItemAsync: async (k: string) => void secure.delete(k),
    AFTER_FIRST_UNLOCK_THIS_DEVICE_ONLY: 'after-first-unlock',
  }
  return { mod, store, secure, opened, encryptedWith, file }
}

const ID = 'gs-guard'

it('opens an already-encrypted store with its key', () => {
  const f = fakes({ [`gs.storage.key.v1.${ID}`]: 'k'.repeat(32) })
  expect(openEncrypted(f.mod, f.store, ID)).not.toBeNull()
  expect(f.opened).toEqual([{ id: ID, encryptionKey: 'k'.repeat(32), encryptionType: 'AES-256' }])
  expect(f.encryptedWith).toEqual([])
})

it('encrypts an older plain store in place, keeping what is in it, and keeps the key only once it is done', () => {
  const f = fakes({}, { 'outbox:v1': '[{"id":"op-1"}]' })
  openEncrypted(f.mod, f.store, ID)
  expect(f.encryptedWith).toHaveLength(1)
  expect(f.encryptedWith[0]).toMatch(/^[0-9a-f]{32}$/)
  expect(f.secure.get(`gs.storage.key.v1.${ID}`)).toBe(f.encryptedWith[0])
  // The queue is still there, and the sentinel is gone.
  expect(f.file.get('outbox:v1')).toBe('[{"id":"op-1"}]')
  expect(f.file.has('__gs_plain_sentinel')).toBe(false)
})

it('a first opening that died BEFORE encrypting: the sentinel says plain, so it is encrypted now with the pending key', () => {
  const f = fakes({ [`gs.storage.key.v1.pending.${ID}`]: 'p'.repeat(32) }, { __gs_plain_sentinel: 'plain', 'outbox:v1': '[]' })
  openEncrypted(f.mod, f.store, ID)
  expect(f.encryptedWith).toEqual(['p'.repeat(32)])
  expect(f.secure.get(`gs.storage.key.v1.${ID}`)).toBe('p'.repeat(32))
})

it('a first opening that died AFTER encrypting: no sentinel readable, so it is opened with the pending key: nothing is encrypted twice', () => {
  const f = fakes({ [`gs.storage.key.v1.pending.${ID}`]: 'p'.repeat(32) })
  openEncrypted(f.mod, f.store, ID)
  expect(f.encryptedWith).toEqual([])
  expect(f.opened.at(-1)).toEqual({ id: ID, encryptionKey: 'p'.repeat(32), encryptionType: 'AES-256' })
  expect(f.secure.get(`gs.storage.key.v1.${ID}`)).toBe('p'.repeat(32))
})

it('with no secure store to keep a key in, says so (null) rather than inventing one', () => {
  const f = fakes()
  const broken = { ...f.store, getItem: () => { throw new Error('no keychain') } }
  expect(openEncrypted(f.mod, broken, ID)).toBeNull()
})
