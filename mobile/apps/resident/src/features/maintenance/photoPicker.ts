/**
 * Photos for a maintenance request — through `expo-image-picker` (in Expo Go and in a build alike).
 *
 * It is still detected, never assumed: were the module ever absent, the board's photo tile is drawn,
 * disabled, with its reason, and the request goes without pictures. Every photo is made small ON THE
 * PHONE before anything is queued — longest side 1600px, JPEG quality 0.6, no EXIF (a photo's hidden
 * GPS tag says exactly where someone lives) — and only the LOCAL uri is kept: the file itself goes up
 * through the outbox, after the request it belongs to has its id.
 *
 * The board says "Photos & video", and the screen says so (M42 §5 #10). Video is still not offered, though the
 * server's tickets.media takes MP4 and MOV: nothing on the phone can re-encode a video, so its hidden location tag
 * would go up with it (D-M076; the store declarations name photos only) — and a 50 MB clip through a queue on one
 * bar of signal holds every other saved act behind it. The "+" adds photos.
 *
 * The `require`s sit in try/catch so Metro treats the modules as optional and bundles without them.
 */

import { photoLimits } from '@gs/core/lib'
import { PHOTO_QUALITY, type Photo } from './tickets'

export const PHOTOS_UNAVAILABLE = "Photos can't be added in this version of the app. Describe the problem in words: the office can ask for pictures."

type PickerAsset = { uri: string; mimeType?: string | null; fileName?: string | null; width?: number; height?: number }
type PickerResult = { canceled: boolean; assets: PickerAsset[] | null }
type PickerOptions = { mediaTypes: ('images' | 'videos')[]; quality: number; exif: boolean; allowsMultipleSelection?: boolean; selectionLimit?: number; allowsEditing?: boolean; aspect?: [number, number] }

/** The part of expo-image-picker this file uses. */
type PickerModule = {
  requestCameraPermissionsAsync: () => Promise<{ granted: boolean }>
  launchCameraAsync: (options: PickerOptions) => Promise<PickerResult>
  launchImageLibraryAsync: (options: PickerOptions) => Promise<PickerResult>
}

let loaded: PickerModule | null | undefined

function pickerModule(): PickerModule | null {
  if (loaded !== undefined) return loaded
  loaded = null
  try {
    const mod = require('expo-image-picker') as Partial<PickerModule> | undefined
    if (mod && typeof mod.launchCameraAsync === 'function' && typeof mod.launchImageLibraryAsync === 'function' && typeof mod.requestCameraPermissionsAsync === 'function') loaded = mod as PickerModule
  } catch {
    loaded = null
  }
  return loaded
}

/** Both halves are needed: the picker to choose a photo, and the re-encoder that strips its hidden tags. Without either the tile is drawn disabled, with its reason. */
export const photosAvailable = (): boolean => {
  if (pickerModule() === null) return false
  try {
    const mod = require('expo-image-manipulator') as { manipulateAsync?: unknown } | undefined
    return typeof mod?.manipulateAsync === 'function'
  } catch {
    return false
  }
}

/** Longest side a photo is sent at. A 12-megapixel original is ~4 MB; at 1600px and quality 0.6 it is ~250 KB. */
export const PHOTO_MAX_SIDE = 1600

type Manipulator = { manipulateAsync: (uri: string, actions: { resize: { width?: number; height?: number } }[], options: { compress: number; format: 'jpeg' }) => Promise<{ uri: string }> }

/**
 * Made small — and written out AFRESH — on the phone, before anything is queued. EVERY photo goes through
 * it, not only the large ones: the picker's `exif: false` only keeps the tags out of its ANSWER; the file
 * it hands over can still carry them (the camera's GPS tag among them). Re-encoding the pixels is what
 * leaves them behind, so a small photo is re-encoded too, just not resized.
 * IT FAILS CLOSED. A photo of someone's home that cannot be re-encoded would go up with its hidden GPS tag,
 * so it is NOT added, and the resident is told — the request is still sent, in words. (The same rule as the
 * security-report picker. Data-light mode asks for a smaller side and a lower quality; nothing else changes.)
 */
async function shrink(asset: PickerAsset, maxSide = PHOTO_MAX_SIDE): Promise<{ uri: string; mime: string } | null> {
  const { width, height } = asset
  try {
    const mod = require('expo-image-manipulator') as Partial<Manipulator> | undefined
    if (!mod || typeof mod.manipulateAsync !== 'function') return null
    const limits = photoLimits()
    const side = Math.min(maxSide, limits.maxSide)
    const large = !!width && !!height && Math.max(width, height) > side
    const actions = large ? [{ resize: width >= height ? { width: side } : { height: side } }] : []
    const out = await mod.manipulateAsync(asset.uri, actions, { compress: Math.min(PHOTO_QUALITY, limits.quality), format: 'jpeg' })
    return { uri: out.uri, mime: 'image/jpeg' }
  } catch {
    return null
  }
}

/**
 * ONE photo, cropped square by the phone's own picker and made small — the resident's profile photo (the client's
 * report R10, 26 Sep). The same rules as a request's photos: re-encoded as JPEG with no EXIF, and it fails closed.
 * What went wrong is said as a reason, not a sentence: the profile screen words it for itself.
 */
export type PickOneOutcome = { photo: Photo | null; trouble: 'camera_off' | 'not_prepared' | 'failed' | null }

export async function pickOne(source: 'camera' | 'library', maxSide: number): Promise<PickOneOutcome> {
  const picker = pickerModule()
  if (!picker) return { photo: null, trouble: 'failed' }
  const options: PickerOptions = { mediaTypes: ['images'], quality: PHOTO_QUALITY, exif: false, allowsEditing: true, aspect: [1, 1] }
  try {
    if (source === 'camera') {
      const permission = await picker.requestCameraPermissionsAsync()
      if (!permission.granted) return { photo: null, trouble: 'camera_off' }
    }
    const result = source === 'camera' ? await picker.launchCameraAsync(options) : await picker.launchImageLibraryAsync(options)
    const asset = result.canceled ? null : result.assets?.[0]
    if (!asset) return { photo: null, trouble: null }
    const small = await shrink(asset, maxSide)
    return small ? { photo: { uri: small.uri, mime: small.mime, fileName: null }, trouble: null } : { photo: null, trouble: 'not_prepared' }
  } catch {
    return { photo: null, trouble: 'failed' }
  }
}

export type PickOutcome = { photos: Photo[]; trouble: string | null }

const NOTHING: PickOutcome = { photos: [], trouble: null }

export async function pickPhotos(source: 'camera' | 'library', room: number): Promise<PickOutcome> {
  const picker = pickerModule()
  if (!picker || room <= 0) return NOTHING
  const options: PickerOptions = { mediaTypes: ['images'], quality: PHOTO_QUALITY, exif: false }
  try {
    if (source === 'camera') {
      const permission = await picker.requestCameraPermissionsAsync()
      if (!permission.granted) return { photos: [], trouble: 'The camera is switched off for this app. Choose a photo you already have, or send the request without one.' }
    }
    const result = source === 'camera' ? await picker.launchCameraAsync(options) : await picker.launchImageLibraryAsync({ ...options, allowsMultipleSelection: room > 1, selectionLimit: room })
    if (result.canceled || !result.assets) return NOTHING
    const photos: Photo[] = []
    let dropped = 0
    for (const asset of result.assets.slice(0, room)) {
      const small = await shrink(asset)
      if (small) photos.push({ uri: small.uri, mime: small.mime, fileName: null }) // the picker's own file name can name a place or a person
      else dropped += 1
    }
    return { photos, trouble: dropped === 0 ? null : `${dropped === 1 ? 'A photo' : `${dropped} photos`} couldn't be prepared on this phone, so ${dropped === 1 ? "it wasn't" : "they weren't"} added. The request can still be sent without ${dropped === 1 ? 'it' : 'them'}.` }
  } catch {
    return { photos: [], trouble: 'That photo could not be added. The request can still be sent without it.' }
  }
}
