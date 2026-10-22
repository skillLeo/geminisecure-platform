import { avatarOf, photoBody, photoFileName, photoPath, troubleSentence, waitingSentence, type PhotoOp } from '../profilePhoto'

const SERVER = { url: '/api/v1/me/photo?v=ab12cd34ef56', updated_at: '2026-09-26T10:00:00+00:00' }
const NEWER = { url: '/api/v1/me/photo?v=99ff00aa11bb', updated_at: '2026-09-26T11:00:00+00:00' }

const op = (over: Partial<PhotoOp>): PhotoOp => ({
  id: 'op-1',
  endpoint: 'me.photo.store',
  state: 'pending',
  body: { file: { uri: 'file:///data/docs/outbox-media/abc.jpg', name: 'profile-photo.jpg', type: 'image/jpeg' } },
  error: null,
  result: null,
  lastTriedAt: null,
  ...over,
})

describe('what the avatar shows (R10)', () => {
  it('the server’s photo, or the initials when there is none, with nothing waiting', () => {
    expect(avatarOf({ server: SERVER, serverAt: 1, ops: [] })).toEqual({ local: null, server: SERVER, waiting: null, refusal: null })
    expect(avatarOf({ server: null, serverAt: 1, ops: [] })).toEqual({ local: null, server: null, waiting: null, refusal: null })
  })

  it('a new photo still on this phone is drawn from the phone, and said to be waiting', () => {
    const a = avatarOf({ server: SERVER, serverAt: 1, ops: [op({})] })
    expect(a.local).toBe('file:///data/docs/outbox-media/abc.jpg')
    expect(a.server).toBeNull()
    expect(a.waiting).toEqual({ act: 'store', state: 'pending' })
    expect(avatarOf({ server: SERVER, serverAt: 1, ops: [op({ state: 'syncing' })] }).waiting).toEqual({ act: 'store', state: 'syncing' })
  })

  it('a removal still on this phone shows the initials at once, never the photo it removes', () => {
    const a = avatarOf({ server: SERVER, serverAt: 1, ops: [op({ endpoint: 'me.photo.remove', body: {} })] })
    expect(a).toEqual({ local: null, server: null, waiting: { act: 'remove', state: 'pending' }, refusal: null })
  })

  it('the LAST act decides: a photo then a removal is a removal; a removal then a photo is the photo', () => {
    const store = op({ id: 's' })
    const remove = op({ id: 'r', endpoint: 'me.photo.remove', body: {} })
    expect(avatarOf({ server: SERVER, serverAt: 1, ops: [store, remove] }).waiting?.act).toBe('remove')
    expect(avatarOf({ server: SERVER, serverAt: 1, ops: [remove, store] }).local).toBe('file:///data/docs/outbox-media/abc.jpg')
  })

  it('a refusal is said in the server’s words, beside the photo the estate still has', () => {
    const a = avatarOf({ server: SERVER, serverAt: 1, ops: [op({ state: 'refused', error: { message: 'That photo is too large. Choose another, or take a new one.' } })] })
    expect(a.server).toEqual(SERVER)
    expect(a.local).toBeNull()
    expect(a.refusal).toEqual({ opId: 'op-1', act: 'store', message: 'That photo is too large. Choose another, or take a new one.' })
    expect(avatarOf({ server: SERVER, serverAt: 1, ops: [op({ state: 'refused', error: { message: '  ' } })] }).refusal?.message).toBe('The estate did not accept that.')
  })

  it('a confirmed photo is drawn from the server’s answer until me.show has been answered since: no flash of the old one', () => {
    const landed = op({ state: 'confirmed', result: { photo: NEWER }, lastTriedAt: '2026-09-26T11:00:00.000Z' })
    const at = Date.parse('2026-09-26T11:00:00.000Z')
    expect(avatarOf({ server: SERVER, serverAt: at - 5_000, ops: [landed] }).server).toEqual(NEWER)
    expect(avatarOf({ server: SERVER, serverAt: undefined, ops: [landed] }).server).toEqual(NEWER)
    // Answered since: me.show decides — a photo changed later on another phone is not hidden by this phone's older act.
    expect(avatarOf({ server: SERVER, serverAt: at + 5_000, ops: [landed] }).server).toEqual(SERVER)
  })

  it('a confirmed removal is the initials until me.show has been answered since', () => {
    const landed = op({ endpoint: 'me.photo.remove', body: {}, state: 'confirmed', result: { photo: null }, lastTriedAt: '2026-09-26T11:00:00.000Z' })
    const at = Date.parse('2026-09-26T11:00:00.000Z')
    expect(avatarOf({ server: SERVER, serverAt: at - 1, ops: [landed] }).server).toBeNull()
    expect(avatarOf({ server: NEWER, serverAt: at + 1, ops: [landed] }).server).toEqual(NEWER)
  })

  it('ignores every other act in the queue', () => {
    expect(avatarOf({ server: SERVER, serverAt: 1, ops: [op({ endpoint: 'me.update', body: { email: 'a@b.c' } })] })).toEqual({ local: null, server: SERVER, waiting: null, refusal: null })
  })
})

describe('fetching it with the token', () => {
  it('turns the server’s path into the API’s own path, keeping the version', () => {
    expect(photoPath('/api/v1/me/photo?v=ab12cd34ef56')).toBe('/me/photo?v=ab12cd34ef56')
    expect(photoPath('https://phoenixparkvillage1.geminisl.com/api/v1/me/photo?v=ab12')).toBe('/me/photo?v=ab12')
    expect(photoPath('/me/photo?v=ab12')).toBe('/me/photo?v=ab12')
  })

  it('keeps one file per version, under a name that is always one safe segment', () => {
    expect(photoFileName('/api/v1/me/photo?v=ab12cd34ef56')).toBe('profile-photo-ab12cd34ef56.jpg')
    expect(photoFileName('/api/v1/me/photo?v=../../etc')).toBe('profile-photo-current.jpg')
    expect(photoFileName('/api/v1/me/photo')).toBe('profile-photo-current.jpg')
  })

  it('sends one multipart field, `file`, named for nothing and nobody', () => {
    expect(photoBody('file:///x/y.jpg')).toEqual({ file: { uri: 'file:///x/y.jpg', name: 'profile-photo.jpg', type: 'image/jpeg' } })
  })
})

describe('what the tab says', () => {
  it('never claims a change reached the estate while it waits', () => {
    for (const act of ['store', 'remove'] as const) {
      for (const state of ['pending', 'syncing'] as const) {
        for (const online of [true, false]) {
          const line = waitingSentence({ act, state }, online)
          expect(line).toMatch(/^(Pending sync|Syncing) · /)
          expect(line).not.toMatch(/\b(saved to the estate|updated|done)\b/i)
        }
      }
    }
    expect(waitingSentence({ act: 'remove', state: 'pending' }, true)).toContain('The estate keeps your photo until it is sent.')
    expect(waitingSentence({ act: 'store', state: 'pending' }, false)).toContain('It goes when you are connected.')
  })

  it('words every picker problem for this screen', () => {
    expect(troubleSentence('camera_off', 'camera')).toContain('camera is switched off')
    expect(troubleSentence('not_prepared', 'library')).toContain('could not be prepared')
    expect(troubleSentence('failed', 'camera')).toContain('camera could not be opened')
    expect(troubleSentence('failed', 'library')).toContain('photos could not be opened')
  })
})
