const mockFiles = new Map<string, true>()

jest.mock(
  'expo-file-system/legacy',
  () => ({
    documentDirectory: 'file:///data/app/documents/',
    getInfoAsync: jest.fn(async (uri: string) => ({ exists: mockFiles.has(uri) })),
    makeDirectoryAsync: jest.fn(async () => undefined),
    copyAsync: jest.fn(async ({ from, to }: { from: string; to: string }) => { if (!mockFiles.has(from)) throw new Error('no such file'); mockFiles.set(to, true) }),
    deleteAsync: jest.fn(async (uri: string) => { mockFiles.delete(uri) }),
  }),
  // Not `virtual` (see download.test.ts): a virtual mock of one path is shared by every file a worker runs.
)

import { FILE_GONE, extensionOf, filePartOf, isKept, keepBody, keepForUpload, stillThere, withMedia } from '../media'

const CACHE = 'file:///data/app/cache/ImageManipulator/abc.jpg'
const body = (uri: string) => ({ file: { uri, name: 'photo-1.jpg', type: 'image/jpeg' } })
const ok = { data: { id: 5 } }
const err = (status: number | string, code = 'x') => ({ error: { status, code, message: 'm', fieldErrors: {}, retryAfter: null } })

beforeEach(() => { mockFiles.clear(); mockFiles.set(CACHE, true) })

describe('a photo waiting to be uploaded is kept where the OS will not sweep it', () => {
  it('is copied into the app\'s own documents folder before it is queued', async () => {
    const kept = await keepForUpload(CACHE)
    expect(kept).toMatch(/^file:\/\/\/data\/app\/documents\/outbox-media\/[0-9a-f-]+\.jpg$/)
    expect(isKept(kept)).toBe(true)
    expect(await stillThere(kept)).toBe(true)
  })

  it('keepBody swaps only the file\'s uri; a body with no file is returned untouched', async () => {
    const out = await keepBody(body(CACHE))
    expect(out.file.uri).not.toBe(CACHE)
    expect(out.file).toMatchObject({ name: 'photo-1.jpg', type: 'image/jpeg' })
    const plain = { title: 'Street light' }
    expect(await keepBody(plain)).toBe(plain)
    expect(await keepBody(null)).toBeNull()
  })

  it('a copy that cannot be made never drops the photo: the original uri stays', async () => {
    expect(await keepForUpload('file:///data/app/cache/gone.jpg')).toBe('file:///data/app/cache/gone.jpg')
  })

  it('is not copied twice', async () => {
    const kept = await keepForUpload(CACHE)
    expect(await keepForUpload(kept)).toBe(kept)
  })

  it('reads an extension, and says jpg when there is none', () => {
    expect(extensionOf('file:///x/y/photo.PNG')).toBe('png')
    expect(extensionOf('file:///x/y/photo')).toBe('jpg')
    expect(filePartOf({ file: { uri: 'file:///a.jpg', name: 'a', type: 'image/jpeg' } })?.uri).toBe('file:///a.jpg')
    expect(filePartOf({ title: 'x' })).toBeNull()
  })
})

describe('the sender, wrapped', () => {
  it('a file that is GONE is a refusal with a plain sentence: the send is never attempted, so the queue moves on', async () => {
    const send = jest.fn(async () => ok)
    const answer = await withMedia(send)({ body: body('file:///data/app/cache/swept.jpg') }, {})
    expect(send).not.toHaveBeenCalled()
    expect(answer).toEqual({ error: { ...FILE_GONE, fieldErrors: {}, retryAfter: null } })
    expect(FILE_GONE.status).toBeGreaterThanOrEqual(400)
    expect(FILE_GONE.status).toBeLessThan(500) // the outbox reads 4xx as "answered no": never resent, never blocking
  })

  it('once the server has the photo, the kept copy is removed', async () => {
    const kept = await keepForUpload(CACHE)
    await withMedia(async () => ok)({ body: body(kept) }, {})
    expect(await stillThere(kept)).toBe(false)
  })

  it('a server refusal removes it too; a NETWORK failure keeps it for the retry', async () => {
    const refused = await keepForUpload(CACHE)
    await withMedia(async () => err(422))({ body: body(refused) }, {})
    expect(await stillThere(refused)).toBe(false)

    const waiting = await keepForUpload(CACHE)
    await withMedia(async () => err('network'))({ body: body(waiting) }, {})
    await withMedia(async () => err(503))({ body: body(waiting) }, {})
    await withMedia(async () => err(409, 'request_in_progress'))({ body: body(waiting) }, {})
    expect(await stillThere(waiting)).toBe(true)
  })

  it('never deletes a file it did not make', async () => {
    await withMedia(async () => ok)({ body: body(CACHE) }, {})
    expect(mockFiles.has(CACHE)).toBe(true)
  })

  it('an act with no file goes straight through', async () => {
    const send = jest.fn(async () => ok)
    expect(await withMedia(send)({ body: { title: 'x' } }, { a: 1 })).toBe(ok)
    expect(send).toHaveBeenCalledWith({ body: { title: 'x' } }, { a: 1 })
  })
})
