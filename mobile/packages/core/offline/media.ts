/**
 * A photo that is waiting to be uploaded must still BE there when its turn comes.
 *
 * The picker and the image shrinker write into the phone's CACHE directory, which the OS may empty
 * whenever it likes. A report queued on a night shift with no signal can wait hours; if its photo has
 * been swept away by then, the upload fails in a way that looks exactly like "no connection" — the act
 * stays pending, and because the queue is strictly ordered, EVERYTHING behind it waits for ever.
 * (Found by the maintenance review, 2026-09-20.) So:
 *
 *   keepForUpload(uri)   copies the file into the app's own documents folder (never swept, never backed
 *                        up to anybody else, removed with the app) and returns the copy's uri. If the
 *                        copy cannot be made the original uri is returned — a photo is never dropped
 *                        because a tidy-up step failed.
 *   stillThere(uri)      true / false, or null where it cannot be known (web, module missing).
 *   discardUpload(uri)   removes a copy THIS file made, once the server has the photo or has refused it.
 *                        It never touches anything outside its own folder.
 *
 * The sender asks stillThere() before an upload: a file that is gone is a REFUSAL with a plain sentence
 * ("the photo is no longer on this phone"), so the queue moves on instead of wedging.
 */

import { Platform } from 'react-native'
import { newUuid } from '../lib/uuid'

type Legacy = {
  documentDirectory: string | null
  getInfoAsync: (uri: string) => Promise<{ exists: boolean }>
  makeDirectoryAsync: (uri: string, options: { intermediates: boolean }) => Promise<void>
  copyAsync: (options: { from: string; to: string }) => Promise<void>
  deleteAsync: (uri: string, options: { idempotent: boolean }) => Promise<void>
}

const FOLDER = 'outbox-media/'

function fs(): Legacy | null {
  if (Platform.OS === 'web') return null
  try {
    const mod = require('expo-file-system/legacy') as Partial<Legacy> | undefined
    return mod && typeof mod.getInfoAsync === 'function' && typeof mod.copyAsync === 'function' && mod.documentDirectory ? (mod as Legacy) : null
  } catch {
    return null
  }
}

/** The extension of a file uri, lower-cased, or "jpg" — every photo this app queues has been re-encoded as JPEG. */
export const extensionOf = (uri: string): string => /\.([A-Za-z0-9]{2,5})(?:\?|#|$)/.exec(uri)?.[1]?.toLowerCase() ?? 'jpg'

export const isKept = (uri: string): boolean => uri.includes(`/${FOLDER}`)

export async function keepForUpload(uri: string): Promise<string> {
  const files = fs()
  if (!files || isKept(uri)) return uri
  try {
    const folder = `${files.documentDirectory}${FOLDER}`
    await files.makeDirectoryAsync(folder, { intermediates: true }).catch(() => undefined)
    const to = `${folder}${newUuid()}.${extensionOf(uri)}`
    await files.copyAsync({ from: uri, to })
    return to
  } catch {
    return uri
  }
}

export async function stillThere(uri: string): Promise<boolean | null> {
  const files = fs()
  if (!files || !/^file:/.test(uri)) return null
  try {
    return (await files.getInfoAsync(uri)).exists
  } catch {
    return null
  }
}

export async function discardUpload(uri: string): Promise<void> {
  const files = fs()
  if (!files || !isKept(uri)) return
  await files.deleteAsync(uri, { idempotent: true }).catch(() => undefined)
}

/** A deliberate sign-out clears the queue — and with it every photo that was waiting in it. */
export async function discardAllUploads(): Promise<void> {
  const files = fs()
  if (!files) return
  await files.deleteAsync(`${files.documentDirectory}${FOLDER}`, { idempotent: true }).catch(() => undefined)
}

export const FILE_GONE = { status: 410 as const, code: 'file_missing', message: 'This photo is no longer on this phone, so it could not be sent. The report itself was sent without it: add the photo again if it still matters.' }

type Body = Record<string, unknown> | null | undefined
type FilePart = { uri: string; name: string; type: string }

/** The file part of a queued upload's body, if it has one. */
export const filePartOf = (body: Body): FilePart | null => {
  const file = body?.file as Partial<FilePart> | undefined
  return file && typeof file.uri === 'string' ? (file as FilePart) : null
}

/** BEFORE an upload is queued: its file is copied somewhere the OS will not sweep. Never throws; never drops the photo. */
export async function keepBody<T extends Body>(body: T): Promise<T> {
  const file = filePartOf(body)
  if (!body || !file) return body
  return { ...body, file: { ...file, uri: await keepForUpload(file.uri) } }
}

type Answer = { data: unknown } | { error: { status: number | string; code: string; message: string; fieldErrors: Record<string, string[]>; retryAfter: number | null } }

/**
 * Wraps an app's sender: a file that is gone is a REFUSAL (the queue moves on), and once the server has
 * answered for good — taken it, or refused it — the kept copy is removed. A network failure keeps it.
 */
export function withMedia<Op extends { body: Body }, Params>(send: (op: Op, params: Params) => Promise<Answer>): (op: Op, params: Params) => Promise<Answer> {
  return async (op, params) => {
    const file = filePartOf(op.body)
    if (file && (await stillThere(file.uri)) === false) return { error: { ...FILE_GONE, fieldErrors: {}, retryAfter: null } }
    const answer = await send(op, params)
    if (file) {
      const final = 'data' in answer || (typeof answer.error.status === 'number' && answer.error.status >= 400 && answer.error.status < 500 && answer.error.status !== 401 && answer.error.code !== 'request_in_progress' && answer.error.code !== 'rate_limited')
      if (final) await discardUpload(file.uri)
    }
    return answer
  }
}
