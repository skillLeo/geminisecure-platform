/**
 * The profile photo on the Profile tab (the client's report R10, 26 Sep) — profilePhoto.ts decides what the avatar
 * shows; this fetches it, and changes it.
 *
 *   SHOW     the server's photo is fetched with this phone's token (`me.photo`, never a public address) into the
 *            downloads folder, one file per version: it opens with no connection, and sign-out takes it with every
 *            other fetched file. A version replaced by a newer one is taken off the phone.
 *   CHANGE   "Take a photo" / "Choose from your photos": the phone's own picker, cropped square, made small and
 *            written out afresh with no EXIF (maintenance/photoPicker.ts, pickOne) — then saved on this phone and
 *            sent like any change (`me.photo.store`, which the contract queues). The sheet closes BEFORE the picker
 *            opens: a picker presented over a modal does not open on every phone.
 *   REMOVE   "Remove your photo", queued the same way (`me.photo.remove`); the initials come back at once, and the
 *            tab says the estate keeps the photo until the removal is sent.
 *   NEVER    in a demonstration (nothing is queued there — the sheet says why); on the board; for an account with
 *            no household (every change is refused there).
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { discardDownload, downloadFile } from '@gs/core/api'
import { useDemoRefusal } from '@/features/auth/useDemo'
import { photosAvailable, pickOne } from '@/features/maintenance/photoPicker'
import { DemoWriteRefused, outbox, submit } from '@/offline/runtime'
import { useAppSelector } from '@/store'
import { PHOTO_ENDPOINTS, PHOTO_KIND, PROFILE_PHOTO_SIDE, avatarOf, photoBody, photoFileName, photoPath, troubleSentence, waitingSentence, type PhotoOp, type PhotoShape } from './profilePhoto'

export type PhotoModel = {
  /** What the avatar draws: a picture on this phone, or null for the initials. */
  uri: string | null
  /** The account has a photo, or is about to: "Remove your photo" is offered. */
  hasPhoto: boolean
  /** Whether it can be changed here at all. When false the pencil opens the contact details, as it always did. */
  canChange: boolean
  /** Why nothing can be changed right now (a demonstration) — the sheet's buttons are disabled with it. */
  blocked: string | null
  /** A change still on this phone, said under the name. */
  waitingLine: string | null
  /** "Preparing your photo…", or what went wrong with the picker. */
  /** A photo that could not be read or saved is an ordinary problem: amber (M98), never red. */
  note: { text: string; tone: 'slate-600' | 'amber-700' } | null
  /** The server did not accept the last change: its words; `dismiss` clears it once read. */
  refusal: { message: string; dismiss: () => void } | null
  sheet: { open: boolean; ask: () => void; cancel: () => void; take: () => void; choose: () => void; remove: () => void }
}

/** Long enough for the sheet's own close to finish before the phone's picker is presented. */
const SHEET_CLOSE_MS = 350

export function useProfilePhoto(input: { shape: PhotoShape; serverAt: number | undefined; enabled: boolean }): PhotoModel {
  const online = useAppSelector((s) => s.network.online)
  const view = useAppSelector((s) => s.outbox.ops)
  const refusal = useDemoRefusal()

  // The store mirrors the queue without bodies or answers; the queue itself has both.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const ops = useMemo<readonly PhotoOp[]>(() => (input.enabled ? (outbox.all().filter((op) => (PHOTO_ENDPOINTS as readonly string[]).includes(op.endpoint)) as PhotoOp[]) : []), [view, input.enabled])
  const avatar = avatarOf({ server: input.shape ?? null, serverAt: input.serverAt, ops })

  /* ---- the server's photo, fetched with the token and kept by version ---- */
  const [fetched, setFetched] = useState<{ url: string; uri: string } | null>(null)
  const wantUrl = input.enabled ? (avatar.server?.url ?? null) : null
  const kept = useRef<string | null>(null)
  useEffect(() => {
    if (!wantUrl) return
    let live = true
    void downloadFile({ path: photoPath(wantUrl), folder: 'account-photo', name: photoFileName(wantUrl), online }).then((got) => {
      if (!live || !got.ok) return
      // The version this replaces is taken off the phone; the new one stays for next time.
      if (kept.current && kept.current !== got.uri) void discardDownload(kept.current)
      kept.current = got.uri
      setFetched({ url: wantUrl, uri: got.uri })
    })
    return () => {
      live = false
    }
  }, [wantUrl, online])

  // A removal the estate has confirmed: the old picture leaves the phone too.
  const settledEmpty = input.enabled && !avatar.server && !avatar.waiting && !avatar.refusal
  useEffect(() => {
    if (!settledEmpty || !kept.current) return
    void discardDownload(kept.current)
    kept.current = null
    setFetched(null)
  }, [settledEmpty])

  const serverUri = fetched && avatar.server && fetched.url === avatar.server.url ? fetched.uri : null
  const uri = avatar.local ?? serverUri

  /* ---- changing it ---- */
  const [open, setOpen] = useState(false)
  const [note, setNote] = useState<PhotoModel['note']>(null)
  const busy = useRef(false)

  const pick = useCallback(async (source: 'camera' | 'library') => {
    if (busy.current || refusal) return
    busy.current = true
    setOpen(false)
    setNote(null)
    try {
      await new Promise((resolve) => setTimeout(resolve, SHEET_CLOSE_MS))
      const got = await pickOne(source, PROFILE_PHOTO_SIDE)
      if (got.trouble) return setNote({ text: troubleSentence(got.trouble, source), tone: 'amber-700' })
      if (!got.photo) return // closed without choosing: nothing changed, nothing to say
      setNote({ text: 'Preparing your photo…', tone: 'slate-600' })
      await submit({ endpoint: 'me.photo.store', body: photoBody(got.photo.uri), label: 'Profile photo', kind: PHOTO_KIND })
      setNote(null)
    } catch (e) {
      setNote({ text: e instanceof DemoWriteRefused ? e.message : 'That photo could not be saved on this phone, so nothing was changed. Try again.', tone: 'amber-700' })
    } finally {
      busy.current = false
    }
  }, [refusal])

  const remove = useCallback(async () => {
    if (busy.current || refusal) return
    busy.current = true
    setOpen(false)
    setNote(null)
    try {
      await submit({ endpoint: 'me.photo.remove', body: {}, label: 'Remove profile photo', kind: PHOTO_KIND })
    } catch (e) {
      setNote({ text: e instanceof DemoWriteRefused ? e.message : 'That could not be saved on this phone, so your photo was not removed. Try again.', tone: 'amber-700' })
    } finally {
      busy.current = false
    }
  }, [refusal])

  const refused = avatar.refusal
  const canChange = input.enabled && photosAvailable()
  return {
    uri,
    hasPhoto: uri !== null || !!avatar.server,
    canChange,
    blocked: refusal,
    waitingLine: avatar.waiting ? waitingSentence(avatar.waiting, online) : null,
    note,
    refusal: refused
      ? { message: `${refused.act === 'store' ? 'Your new photo was not accepted' : 'Your photo was not removed'}: ${refused.message}`, dismiss: () => void outbox.dismiss(refused.opId) }
      : null,
    sheet: {
      open: open && canChange,
      ask: () => setOpen(true),
      cancel: () => setOpen(false),
      take: () => void pick('camera'),
      choose: () => void pick('library'),
      remove: () => void remove(),
    },
  }
}
