/**
 * Photos for a security report — through `expo-image-picker` (in Expo Go and in a build alike).
 *
 * It is still detected, never assumed: were a module ever absent, the board's photo tile is drawn,
 * disabled, with its reason, and the report goes without pictures. Every photo is made small ON THE
 * PHONE before anything is queued — longest side 1600px, JPEG quality 0.6, no EXIF (a photo's hidden
 * GPS tag says exactly where someone lives, and who was standing where) — and only the LOCAL uri is
 * kept: the file itself goes up through the outbox, after the report it belongs to has its id.
 *
 * NO EXIF IS A PROMISE, SO IT FAILS CLOSED. The form tells the resident "the app does not send your
 * phone's location". The picker's `exif: false` only keeps the tags out of its ANSWER — the file it hands
 * over can still carry them. Writing the pixels out afresh (`expo-image-manipulator`) is what leaves them
 * behind. So EVERY photo is re-encoded, small ones too; and a photo that could NOT be re-encoded is not
 * added at all — said plainly, and the report goes without it. (Here the maintenance picker keeps the
 * original instead: a dripping tap's photo is not a security report's.)
 *
 * The board draws a video thumb. Video is not offered: a 50 MB clip through a queue on one bar of
 * signal holds every other saved act behind it. Photos only, and said so on the screen.
 *
 * The `require`s sit in try/catch so Metro treats the modules as optional and bundles without them.
 * (The same pattern as the maintenance request's picker, kept in this folder on purpose: the two
 * features are built and changed independently.)
 */

import { PHOTO_QUALITY, type Photo } from './incidents'

export const PHOTOS_UNAVAILABLE = "Photos can't be added in this version of the app. Describe what you saw in words: the office can ask for pictures."

type PickerAsset = { uri: string; mimeType?: string | null; fileName?: string | null; width?: number; height?: number }
type PickerResult = { canceled: boolean; assets: PickerAsset[] | null }
type PickerOptions = { mediaTypes: ('images' | 'videos')[]; quality: number; exif: boolean; allowsMultipleSelection?: boolean; selectionLimit?: number }

/** The part of expo-image-picker this file uses. */
type PickerModule = {
  requestCameraPermissionsAsync: () => Promise<{ granted: boolean }>
  launchCameraAsync: (options: PickerOptions) => Promise<PickerResult>
  launchImageLibraryAsync: (options: PickerOptions) => Promise<PickerResult>
}

/** The part of expo-image-manipulator this file uses. */
type Manipulator = { manipulateAsync: (uri: string, actions: { resize: { width?: number; height?: number } }[], options: { compress: number; format: 'jpeg' }) => Promise<{ uri: string }> }

let picker: PickerModule | null | undefined
let manipulator: Manipulator | null | undefined

function pickerModule(): PickerModule | null {
  if (picker !== undefined) return picker
  picker = null
  try {
    const mod = require('expo-image-picker') as Partial<PickerModule> | undefined
    if (mod && typeof mod.launchCameraAsync === 'function' && typeof mod.launchImageLibraryAsync === 'function' && typeof mod.requestCameraPermissionsAsync === 'function') picker = mod as PickerModule
  } catch {
    picker = null
  }
  return picker
}

function manipulatorModule(): Manipulator | null {
  if (manipulator !== undefined) return manipulator
  manipulator = null
  try {
    const mod = require('expo-image-manipulator') as Partial<Manipulator> | undefined
    if (mod && typeof mod.manipulateAsync === 'function') manipulator = mod as Manipulator
  } catch {
    manipulator = null
  }
  return manipulator
}

/** Both or neither: a photo this phone can pick but cannot strip is a photo it must not send. */
export const photosAvailable = (): boolean => pickerModule() !== null && manipulatorModule() !== null

/** Longest side a photo is sent at. A 12-megapixel original is ~4 MB; at 1600px and quality 0.6 it is ~250 KB. */
export const PHOTO_MAX_SIDE = 1600

/** Written out AFRESH on the phone — resized only when large. Null when it could not be: the caller does not keep the photo. */
async function reencode(asset: PickerAsset): Promise<string | null> {
  const tool = manipulatorModule()
  if (!tool) return null
  const { width, height } = asset
  try {
    const large = !!width && !!height && Math.max(width, height) > PHOTO_MAX_SIDE
    const actions = large ? [{ resize: width >= height ? { width: PHOTO_MAX_SIDE } : { height: PHOTO_MAX_SIDE } }] : []
    const out = await tool.manipulateAsync(asset.uri, actions, { compress: PHOTO_QUALITY, format: 'jpeg' })
    return out.uri || null
  } catch {
    return null
  }
}

export type PickOutcome = { photos: Photo[]; trouble: string | null }

const NOTHING: PickOutcome = { photos: [], trouble: null }

const notPrepared = (n: number, of: number): string =>
  of === 1
    ? "That photo couldn't be prepared on this phone, so it wasn't added. The report can still be sent without it."
    : `${n} of those ${of} photos couldn't be prepared on this phone and ${n === 1 ? "wasn't" : "weren't"} added. The report can still be sent without ${n === 1 ? 'it' : 'them'}.`

export async function pickPhotos(source: 'camera' | 'library', room: number): Promise<PickOutcome> {
  const pick = pickerModule()
  if (!pick || !manipulatorModule() || room <= 0) return NOTHING
  const options: PickerOptions = { mediaTypes: ['images'], quality: PHOTO_QUALITY, exif: false }
  try {
    if (source === 'camera') {
      const permission = await pick.requestCameraPermissionsAsync()
      if (!permission.granted) return { photos: [], trouble: 'The camera is switched off for this app. Choose a photo you already have, or send the report without one.' }
    }
    const result = source === 'camera' ? await pick.launchCameraAsync(options) : await pick.launchImageLibraryAsync({ ...options, allowsMultipleSelection: room > 1, selectionLimit: room })
    if (result.canceled || !result.assets) return NOTHING
    const chosen = result.assets.slice(0, room)
    const photos: Photo[] = []
    for (const asset of chosen) {
      const uri = await reencode(asset)
      // The picker's file name can name a person ("Marcia at home.heic"); it is not kept — mediaBody() names the upload.
      if (uri) photos.push({ uri, mime: 'image/jpeg', fileName: null })
    }
    const failed = chosen.length - photos.length
    return { photos, trouble: failed > 0 ? notPrepared(failed, chosen.length) : null }
  } catch {
    return { photos: [], trouble: 'That photo could not be added. The report can still be sent without it.' }
  }
}
