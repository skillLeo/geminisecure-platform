/**
 * The resident's profile photo (the client's report R10, 26 Sep: "the user can change his profile picture in the
 * mobile app") — what the avatar on the Profile tab shows, worked out from the server's answer and this phone's
 * queue. No React here: useProfilePhoto.ts draws on it, and it is tested on its own.
 *
 *   THE SERVER'S   `me.show` says `account.photo`: a path under /api/v1 with a version (`?v=` — the first
 *                  characters of the file's hash), or null. The file is fetched with this phone's token — there is
 *                  no public address for it — and kept on the phone under that version, so it opens with no
 *                  connection and is fetched again only when the photo changes.
 *   WAITING        a new photo, or a removal, is saved on this phone first and sent like any change
 *                  (`me.photo.store`, `me.photo.remove`). While it waits the avatar shows what the resident chose —
 *                  their new photo from the phone, or their initials — and says it has not reached the estate.
 *   CONFIRMED      the server's answer to the act is the photo until `me.show` has been answered SINCE (it is asked
 *                  again as soon as the act lands); from then on `me.show` decides — a photo changed on another
 *                  phone later is not hidden by this phone's older act.
 *   REFUSED        said in the server's words, beside the photo it still has; nothing changed.
 */

export const PHOTO_KIND = 'profile-photo'
export const PHOTO_ENDPOINTS = ['me.photo.store', 'me.photo.remove'] as const

/** The profile photo is shown at 64 points; 800 px keeps it sharp on any phone at a fraction of a camera's file. */
export const PROFILE_PHOTO_SIDE = 800

export type PhotoShape = { url: string; updated_at?: string | null } | null

/** What this file needs of an outbox record (structurally the outbox's own `OutboxOp`). */
export type PhotoOp = {
  id: string
  endpoint: string
  state: 'pending' | 'syncing' | 'confirmed' | 'refused'
  body: Record<string, unknown> | null
  error: { message: string } | null
  result: unknown
  lastTriedAt: string | null
}

export type Avatar = {
  /** A photo on this phone that has not reached the estate yet: drawn as it is. */
  local: string | null
  /** The server's photo to fetch and draw — null for initials. */
  server: PhotoShape
  /** An act still on this phone, and which. */
  waiting: { act: 'store' | 'remove'; state: 'pending' | 'syncing' } | null
  /** The last act was not accepted: its words, and which record to clear once read. */
  refusal: { opId: string; act: 'store' | 'remove'; message: string } | null
}

const actOf = (op: PhotoOp): 'store' | 'remove' => (op.endpoint === 'me.photo.store' ? 'store' : 'remove')

const fileUriOf = (op: PhotoOp): string | null => {
  const file = (op.body as { file?: { uri?: unknown } } | null)?.file
  return file && typeof file.uri === 'string' ? file.uri : null
}

const resultPhoto = (op: PhotoOp): PhotoShape => {
  const photo = (op.result as { photo?: { url?: unknown; updated_at?: unknown } | null } | null)?.photo
  return photo && typeof photo.url === 'string' ? { url: photo.url, updated_at: typeof photo.updated_at === 'string' ? photo.updated_at : null } : null
}

/**
 * `ops` in the queue's order (oldest first); `serverAt` — when `me.show` was last answered (ms), or undefined when
 * what is drawn is the copy saved on the phone.
 */
export function avatarOf(input: { server: PhotoShape; serverAt: number | undefined; ops: readonly PhotoOp[] }): Avatar {
  const mine = input.ops.filter((op) => (PHOTO_ENDPOINTS as readonly string[]).includes(op.endpoint))
  const last = mine[mine.length - 1]
  const base: Avatar = { local: null, server: input.server, waiting: null, refusal: null }
  if (!last) return base

  if (last.state === 'pending' || last.state === 'syncing') {
    const act = actOf(last)
    return { local: act === 'store' ? fileUriOf(last) : null, server: act === 'store' && !fileUriOf(last) ? input.server : null, waiting: { act, state: last.state }, refusal: null }
  }
  if (last.state === 'refused') {
    return { ...base, refusal: { opId: last.id, act: actOf(last), message: last.error?.message?.trim() || 'The estate did not accept that.' } }
  }
  // Confirmed: the act's own answer, until `me.show` has been answered since it landed.
  const landed = last.lastTriedAt ? Date.parse(last.lastTriedAt) : NaN
  const since = input.serverAt !== undefined && Number.isFinite(landed) && input.serverAt > landed
  if (since) return base
  return { ...base, server: actOf(last) === 'store' ? (resultPhoto(last) ?? input.server) : null }
}

/** `/api/v1/me/photo?v=ab12` → `/me/photo?v=ab12`: the download helper adds the API's own base. */
export const photoPath = (url: string): string => url.replace(/^(?:https?:\/\/[^/]+)?\/api\/v1(?=\/)/, '')

/** The name it is kept under on the phone: one per version, so a changed photo is fetched and an unchanged one never is. */
export function photoFileName(url: string): string {
  const version = /[?&]v=([A-Za-z0-9]{1,64})/.exec(url)?.[1] ?? 'current'
  return `profile-photo-${version}.jpg`
}

/** tickets.media's shape, for `me.photo.store`: multipart, one field, `file`. Every photo this app sends is a JPEG. */
export const photoBody = (uri: string): { file: { uri: string; name: string; type: string } } => ({ file: { uri, name: 'profile-photo.jpg', type: 'image/jpeg' } })

/** Under the name on the Profile tab while an act waits — said plainly, never claimed as done. */
export function waitingSentence(waiting: NonNullable<Avatar['waiting']>, online: boolean): string {
  if (waiting.state === 'syncing') return waiting.act === 'store' ? 'Syncing · sending your new photo to the estate.' : 'Syncing · telling the estate to remove your photo.'
  const what = waiting.act === 'store'
    ? 'Pending sync · your new photo is saved on this phone. The estate has not received it yet.'
    : 'Pending sync · the removal is saved on this phone. The estate keeps your photo until it is sent.'
  return online ? what : `${what} It goes when you are connected.`
}

/** A picker's reason, in words for this screen. */
export function troubleSentence(trouble: 'camera_off' | 'not_prepared' | 'failed', source: 'camera' | 'library'): string {
  if (trouble === 'camera_off') return 'The camera is switched off for this app. Choose a photo you already have instead, or turn the camera on in your phone’s settings.'
  if (trouble === 'not_prepared') return 'That photo could not be prepared on this phone, so it was not used. Try another one.'
  return source === 'camera' ? 'The camera could not be opened. Try again, or choose a photo you already have.' : 'Your photos could not be opened. Try again, or take a new photo.'
}
