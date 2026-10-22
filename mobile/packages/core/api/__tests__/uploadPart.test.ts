/**
 * "Reproduce the photo that could never send, on the emulator, and fix the cause" (M12 §2.7).
 *
 * REPRODUCED on the shipping build (x86_64, the reviewer's demonstration account against geminisl.com): the request
 * was confirmed as #1002 and its photo stayed "1 photo still waiting to upload" through restarts — while the same
 * photo, posted to the same endpoint from a PC, was taken in 1.4 s. The server was never asked.
 *
 * THE CAUSE: Expo SDK 57 installs its own `fetch`, and its FormData encoder throws on React Native's
 * `{ uri, name, type }` part. These tests run THAT encoder — Expo's own convertFormDataAsync, on React Native's own
 * FormData with Expo's own patch — so they fail the way the phone did if the part ever goes back to the old shape.
 */

import { toFormData } from '../runtime'

jest.mock('expo-file-system', () => ({
  File: function MockFile(this: { bytes: () => Promise<Uint8Array> }, uri: string) {
    this.bytes = async () => {
      if (!uri.startsWith('file:///kept/')) throw new Error(`no file at ${uri}`)
      return new Uint8Array([0xff, 0xd8, 0xff, 0xe0, 0x4a, 0x46])
    }
  },
}))

// The phone's own FormData and encoder, not stand-ins — `requireActual`, because jest-expo mocks Expo's runtime patches.
const RNFormData = jest.requireActual<{ default: typeof FormData }>('react-native/Libraries/Network/FormData').default
const { installFormDataPatch } = jest.requireActual<{ installFormDataPatch: (f: typeof FormData) => void }>('expo/src/winter/FormData')
const { convertFormDataAsync } = jest.requireActual<{ convertFormDataAsync: (f: FormData, boundary?: string) => Promise<{ body: Uint8Array }> }>('expo/src/winter/fetch/convertFormData')

const original = global.FormData
beforeAll(() => {
  installFormDataPatch(RNFormData)
  global.FormData = RNFormData
})
afterAll(() => {
  global.FormData = original
})

const photo = { uri: 'file:///kept/outbox-media/0f3c.jpg', name: 'request-photo-1.jpg', type: 'image/jpeg' }
const text = (bytes: Uint8Array) => Buffer.from(bytes).toString('latin1')

it('was the cause: Expo\'s fetch cannot encode React Native\'s { uri, name, type } part', async () => {
  const form = new FormData()
  form.append('file', photo as unknown as Blob)
  await expect(convertFormDataAsync(form)).rejects.toThrow('Unsupported FormDataPart implementation')
})

it('sends the photo through Expo\'s fetch: its bytes, under its own name and type', async () => {
  const { body } = await convertFormDataAsync(toFormData({ file: photo }), 'B')
  const wire = text(body)
  expect(wire).toContain('content-disposition: form-data; name="file"; filename="request-photo-1.jpg"')
  expect(wire).toContain('content-type: image/jpeg')
  expect(body).toEqual(expect.any(Uint8Array))
  expect(wire).toContain(text(new Uint8Array([0xff, 0xd8, 0xff, 0xe0, 0x4a, 0x46])))
})

it('still hands React Native\'s own fetch the part it reads: the uri, and nothing it cannot send across', () => {
  const parts = (toFormData({ file: photo, caption: 'Kitchen tap' }) as unknown as { getParts: () => Record<string, unknown>[] }).getParts()
  expect(parts[0]).toMatchObject({ uri: photo.uri, name: 'request-photo-1.jpg', type: 'image/jpeg' })
  expect(Object.keys(parts[0]!)).not.toContain('bytes')
  expect(parts[1]).toMatchObject({ string: 'Kitchen tap' })
})

it('fails the send, not the phone, when the file has gone: the queue\'s own check refuses it first', async () => {
  await expect(convertFormDataAsync(toFormData({ file: { ...photo, uri: 'file:///swept/0f3c.jpg' } }))).rejects.toThrow('no file at')
})
