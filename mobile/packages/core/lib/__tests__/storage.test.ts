import { createAsyncStorageEngine, jsonStore } from '../storage'

function fakeBackend() {
  const disk = new Map<string, string>()
  let release: (() => void) | null = null
  let gate: Promise<void> | null = null
  return {
    disk,
    /** Make the next disk write wait until `open()` — to prove setDurable really waits. */
    holdWrites() {
      gate = new Promise<void>((r) => (release = r))
    },
    open() {
      release?.()
      gate = null
    },
    getAllKeys: async () => [...disk.keys()],
    multiGet: async (keys: readonly string[]) => keys.map((k) => [k, disk.get(k) ?? null] as [string, string | null]),
    setItem: async (k: string, v: string) => {
      if (gate) await gate
      disk.set(k, v)
    },
    removeItem: async (k: string) => void disk.delete(k),
    multiRemove: async (keys: readonly string[]) => keys.forEach((k) => disk.delete(k)),
  }
}

describe('AsyncStorage engine (Expo Go and web)', () => {
  it('reads synchronously after hydrate, and only its own keys', async () => {
    const backend = fakeBackend()
    backend.disk.set('gs:theme', 'dark')
    backend.disk.set('someone-elses-key', 'x')
    const s = createAsyncStorageEngine(backend)
    expect(s.getString('theme')).toBeNull()
    await s.hydrate()
    expect(s.getString('theme')).toBe('dark')
    expect(s.keys()).toEqual(['theme'])
  })

  it('a write is readable at once and reaches disk behind it', async () => {
    const backend = fakeBackend()
    const s = createAsyncStorageEngine(backend)
    s.set('lang', 'en')
    expect(s.getString('lang')).toBe('en')
    await s.flush()
    expect(backend.disk.get('gs:lang')).toBe('en')
  })

  it('setDurable resolves only once the value is on disk: a guard is never told "saved" early', async () => {
    const backend = fakeBackend()
    const s = createAsyncStorageEngine(backend)
    backend.holdWrites()
    let settled = false
    const saved = s.setDurable('outbox', '[1]').then(() => (settled = true))
    await Promise.resolve()
    await Promise.resolve()
    expect(settled).toBe(false)
    expect(backend.disk.has('gs:outbox')).toBe(false)
    backend.open()
    await saved
    expect(settled).toBe(true)
    expect(backend.disk.get('gs:outbox')).toBe('[1]')
  })

  it('keeps writes in order', async () => {
    const backend = fakeBackend()
    const s = createAsyncStorageEngine(backend)
    s.set('k', '1')
    s.set('k', '2')
    s.remove('k')
    s.set('k', '3')
    await s.flush()
    expect(backend.disk.get('gs:k')).toBe('3')
  })

  it('clearAll removes this app\'s keys and nobody else\'s: logout leaves nothing behind', async () => {
    const backend = fakeBackend()
    backend.disk.set('other', 'keep')
    const s = createAsyncStorageEngine(backend)
    s.set('a', '1')
    s.set('b', '2')
    await s.clearAll()
    expect(s.keys()).toEqual([])
    expect([...backend.disk.keys()]).toEqual(['other'])
  })
})

describe('jsonStore', () => {
  it('round-trips JSON under a versioned key, and ignores another version\'s shape', async () => {
    const s = createAsyncStorageEngine(fakeBackend())
    const v1 = jsonStore<{ n: number }>(s, 'orders', 'v1')
    v1.set({ n: 3 })
    expect(v1.get()).toEqual({ n: 3 })
    expect(jsonStore<{ n: number }>(s, 'orders', 'v2').get()).toBeNull()
  })

  it('returns null for a corrupt value rather than throwing at boot', () => {
    const s = createAsyncStorageEngine(fakeBackend())
    s.set('v1:bad', '{not json')
    expect(jsonStore(s, 'bad', 'v1').get()).toBeNull()
  })
})
