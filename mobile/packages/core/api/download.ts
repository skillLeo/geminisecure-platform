/**
 * A FILE THE API HANDS OVER AS ITSELF — a notice's attachment — fetched with the phone's own token.
 *
 * The generated hooks speak JSON; a PDF is not JSON, and it is far too big to pass through a Redux
 * cache. So a file goes straight from the server to the phone's CACHE folder, and the caller gets a
 * local `file://` address it can hand to the system viewer / share sheet.
 *
 *   - The token is attached HERE, from the same in-memory copy the API layer uses. A feature never
 *     reads it, the address carries no secret, and nothing about the request is logged.
 *   - The server's refusal (404 "not for your household", 403, 401) comes back as the app's one error
 *     shape, with the server's own sentence. The refusal body is read from the file it was written to,
 *     and that file is removed — a JSON error is never left behind under a ".pdf" name.
 *   - A file already fetched is served from the phone — so it still opens with no connection — and the
 *     whole folder goes at sign-out or when the session is revoked (auth/clearEverything).
 *   - On web, and in a build without the file module, the answer is `unsupported`: the caller says so
 *     plainly. Never a fake success.
 *
 * `expo-file-system/legacy` is part of Expo Go; it is still `require`d lazily, the way offline/media.ts
 * does, so a web bundle and Jest never evaluate a native module.
 */

import { Platform } from 'react-native'
import { tokenStorage } from '../auth/tokenStorage'
import { apiUrl } from '../lib/env'
import { normalizeError, type ApiError } from './errors'

type Legacy = {
  cacheDirectory: string | null
  getInfoAsync: (uri: string) => Promise<{ exists: boolean; size?: number }>
  makeDirectoryAsync: (uri: string, options: { intermediates: boolean }) => Promise<void>
  downloadAsync: (url: string, fileUri: string, options: { headers: Record<string, string> }) => Promise<{ status: number; uri: string }>
  readAsStringAsync: (uri: string) => Promise<string>
  writeAsStringAsync: (uri: string, contents: string, options: { encoding: 'base64' }) => Promise<void>
  deleteAsync: (uri: string, options: { idempotent: boolean }) => Promise<void>
}

const FOLDER = 'api-downloads/'

function fs(): Legacy | null {
  if (Platform.OS === 'web') return null
  try {
    const mod = require('expo-file-system/legacy') as Partial<Legacy> | undefined
    return mod && typeof mod.downloadAsync === 'function' && typeof mod.getInfoAsync === 'function' && mod.cacheDirectory ? (mod as Legacy) : null
  } catch {
    return null
  }
}

export type DownloadResult =
  /** The file is on the phone, at `uri`. `fromPhone`: it was already here, and the server was not asked. */
  | { ok: true; uri: string; fromPhone: boolean }
  /** Web, or a build without the file module: files cannot be fetched here at all. */
  | { ok: false; why: 'unsupported' }
  /** The server answered no, or could not be reached — `error` is the app's one error shape. */
  | { ok: false; why: 'failed'; error: ApiError }

/** A name that is safe as ONE path segment: no folders, no "..", nothing a file system chokes on. */
export function safeFileName(name: string): string {
  const base = name.replace(/\\/g, '/').split('/').pop() ?? ''
  const tidy = base.replace(/[^A-Za-z0-9._ -]+/g, '_').replace(/\.{2,}/g, '.').replace(/^[ .]+|[ .]+$/g, '')
  return (tidy || 'file').slice(-120)
}

const failed = (error: ApiError): DownloadResult => ({ ok: false, why: 'failed', error })

const NETWORK: ApiError = { status: 'network', code: 'network_unreachable', message: "You're offline or the server can't be reached, so the file could not be fetched.", fieldErrors: {}, retryAfter: null }
const SIGNED_OUT: ApiError = { status: 401, code: 'unauthenticated', message: 'You are signed out. Sign in again to open this file.', fieldErrors: {}, retryAfter: null }

/**
 * Fetch `path` (an API path, e.g. `/notices/4/attachments/9`) into the cache as `<folder>/<name>`.
 * `folder` keeps two files with the same name apart (one per notice attachment).
 *
 * `fresh`: the server's answer NOW, never a copy fetched before — a person's data export, which changes as their records
 * do. Its folder is emptied first, so it holds this one file: it stays for the app the resident shares it to (which may
 * still be reading it when the sheet closes) until the next fresh fetch, or sign-out.
 */
export async function downloadFile(input: { path: string; folder: string; name: string; online: boolean; fresh?: boolean }): Promise<DownloadResult> {
  const files = fs()
  if (!files) return { ok: false, why: 'unsupported' }

  const dir = `${files.cacheDirectory}${FOLDER}${safeFileName(input.folder)}/`
  const target = `${dir}${safeFileName(input.name)}`

  if (!input.fresh) {
    try {
      const have = await files.getInfoAsync(target)
      if (have.exists && (have.size ?? 1) > 0) return { ok: true, uri: target, fromPhone: true }
    } catch {
      /* not there: fetch it */
    }
  }

  if (!input.online) return failed(NETWORK)
  const token = tokenStorage.peek()
  if (!token) return failed(SIGNED_OUT)

  try {
    if (input.fresh) await files.deleteAsync(dir, { idempotent: true }).catch(() => undefined)
    await files.makeDirectoryAsync(dir, { intermediates: true }).catch(() => undefined)
    const answer = await files.downloadAsync(`${apiUrl()}${input.path}`, target, { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } })
    if (answer.status >= 200 && answer.status < 300) return { ok: true, uri: answer.uri, fromPhone: false }

    // A refusal arrives as JSON, written where the file would have gone. Read its sentence; leave nothing behind.
    let data: unknown = null
    try {
      data = JSON.parse(await files.readAsStringAsync(target))
    } catch {
      /* not the contract's shape: normalizeError says something plain */
    }
    await files.deleteAsync(target, { idempotent: true }).catch(() => undefined)
    return failed(normalizeError({ status: answer.status, data }))
  } catch {
    await files.deleteAsync(target, { idempotent: true }).catch(() => undefined)
    return failed(NETWORK)
  }
}

/**
 * Take ONE fetched file off the phone again.
 *
 * A notice's attachment is worth keeping — it is why it still opens with no connection. A PAYSLIP is
 * not: it is a wage, and the rule it lives under is that it is never written where anything else can
 * read it. So the payslip screen fetches into the app's own cache, hands the file straight to the
 * share sheet, and then calls this — the file exists for as long as the sharing takes and no longer.
 */
export async function discardDownload(uri: string): Promise<void> {
  const files = fs()
  if (!files) return
  await files.deleteAsync(uri, { idempotent: true }).catch(() => undefined)
}

/**
 * A file THIS PHONE made, for the share sheet — a visitor pass's QR code as a picture (the client's report R6,
 * 26 Sep). It is written under the same folder as a fetched file, so sign-out takes it with the rest; its own
 * folder holds ONE file, the last one shared, and the next share replaces it. It is not deleted the moment the
 * sheet closes (the payslip is): the app the resident picked may still be reading it, and a picture that went
 * missing half-way would say the pass was sent when it was not. Null on web, in a build without the file
 * module, or when the phone could not write it — the caller then shares the words alone, and says so.
 */
export async function keepForSharing(input: { folder: string; name: string; base64: string }): Promise<string | null> {
  const files = fs()
  if (!files || typeof files.writeAsStringAsync !== 'function') return null
  const dir = `${files.cacheDirectory}${FOLDER}${safeFileName(input.folder)}/`
  const target = `${dir}${safeFileName(input.name)}`
  try {
    await files.deleteAsync(dir, { idempotent: true }).catch(() => undefined)
    await files.makeDirectoryAsync(dir, { intermediates: true })
    await files.writeAsStringAsync(target, input.base64, { encoding: 'base64' })
    return target
  } catch {
    await files.deleteAsync(target, { idempotent: true }).catch(() => undefined)
    return null
  }
}

/** Sign-out and a revoked session: every file fetched for the last person goes. */
export async function discardAllDownloads(): Promise<void> {
  const files = fs()
  if (!files) return
  await files.deleteAsync(`${files.cacheDirectory}${FOLDER}`, { idempotent: true }).catch(() => undefined)
}
