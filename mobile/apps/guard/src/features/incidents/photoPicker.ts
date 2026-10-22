/**
 * Incident photos — through `expo-image-picker` (in Expo Go and in a build alike).
 *
 * It is still detected, never assumed: were the module ever absent, the board's photo tile is drawn,
 * disabled, with its reason, and a report is filed without pictures. Camera first (a guard has one
 * hand), the gallery as the second choice. Every photo is made small ON THE DEVICE before anything is
 * queued — longest side 1600px, JPEG quality 0.6, no EXIF (a photo's hidden GPS tag is not the
 * guard's to publish) — and only the LOCAL uri is kept: the file itself goes up through the outbox,
 * after the report it belongs to has its id.
 *
 * The `require`s sit in try/catch so Metro treats the modules as optional and bundles without them.
 */

import { photoLimits } from '@gs/core/lib'
import { PHOTO_QUALITY, type Photo } from './incidents'

export const PHOTOS_UNAVAILABLE = "Photos can't be attached in this version of the app. Describe what you saw in the report."

type PickerAsset = { uri: string; mimeType?: string | null; fileName?: string | null; width?: number; height?: number }
type PickerResult = { canceled: boolean; assets: PickerAsset[] | null }
type PickerOptions = { mediaTypes: ('images' | 'videos')[]; quality: number; exif: boolean; allowsMultipleSelection?: boolean; selectionLimit?: number }

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

export const photosAvailable = (): boolean => pickerModule() !== null

/** Longest side a report photo is sent at. A 12-megapixel original is ~4 MB; at 1600px and quality 0.6 it is ~250 KB — the difference between a report that uploads on one bar of signal and one that does not. */
export const PHOTO_MAX_SIDE = 1600

type Manipulator = { manipulateAsync: (uri: string, actions: { resize: { width?: number; height?: number } }[], options: { compress: number; format: 'jpeg' }) => Promise<{ uri: string }> }

/**
 * Written out AFRESH on the device, before anything is queued — EVERY photo, not only the large ones: the
 * picker's `exif: false` only keeps the tags out of its ANSWER; the file can still carry them. Re-encoding
 * the pixels is what leaves them behind (a large photo is also resized).
 *
 * If the re-encoder is missing or fails the photo is KEPT as the picker gave it. That is deliberate and it is
 * the opposite of the Resident App's rule: a guard's photo is evidence of an incident on the client's own
 * estate, going to the security company's own server — losing it is worse than a location tag reaching the
 * people who posted the guard there.
 */
async function shrink(asset: PickerAsset): Promise<{ uri: string; mime: string | null }> {
  const { width, height } = asset
  // Data-light mode sends a smaller photo still (1024 px) — the evidence is the same, the upload is a third the size.
  const limits = photoLimits()
  const side = Math.min(PHOTO_MAX_SIDE, limits.maxSide)
  try {
    const mod = require('expo-image-manipulator') as Partial<Manipulator> | undefined
    if (!mod || typeof mod.manipulateAsync !== 'function') return { uri: asset.uri, mime: null }
    const large = !!width && !!height && Math.max(width, height) > side
    const actions = large ? [{ resize: width >= height ? { width: side } : { height: side } }] : []
    const out = await mod.manipulateAsync(asset.uri, actions, { compress: Math.min(PHOTO_QUALITY, limits.quality), format: 'jpeg' })
    return { uri: out.uri, mime: 'image/jpeg' }
  } catch {
    return { uri: asset.uri, mime: null }
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
      if (!permission.granted) return { photos: [], trouble: 'The camera is switched off for this app. Choose a photo from the gallery, or file the report without one.' }
    }
    const result = source === 'camera' ? await picker.launchCameraAsync(options) : await picker.launchImageLibraryAsync({ ...options, allowsMultipleSelection: room > 1, selectionLimit: room })
    if (result.canceled || !result.assets) return NOTHING
    const photos: Photo[] = []
    for (const asset of result.assets.slice(0, room)) {
      const small = await shrink(asset)
      photos.push({ uri: small.uri, mime: small.mime ?? asset.mimeType ?? null, fileName: asset.fileName ?? null })
    }
    return { photos, trouble: null }
  } catch {
    return { photos: [], trouble: 'That photo could not be added. The report can still be filed without it.' }
  }
}
