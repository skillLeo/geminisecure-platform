/**
 * Opening a file the office put with a notice (board resident-app-36's attachment row).
 *
 *   FETCH   `notices.attachment` hands over THE FILE, not JSON, so it does not go through a generated hook:
 *           core's `downloadFile` fetches it with the phone's token into the cache folder. The list only ever
 *           carries a name, a type, a size and an id — there is no address to leak. A file fetched once is
 *           served from the phone afterwards, so it opens with no connection; all of them go at sign-out.
 *   OPEN    the phone's own share sheet (`expo-sharing`, part of Expo Go): "Open in…", Save to Files, a PDF
 *           viewer, Drive. The app has no viewer of its own and does not pretend to.
 *   HONEST  every way this can fail has its own sentence (notices.ts, openSentence): no connection and not
 *           fetched yet · the server's refusal, in its words · a phone with nothing to open it · the web
 *           preview, which cannot fetch files at all. Nothing is ever reported as opened that was not.
 */

import { useCallback, useMemo, useRef, useState } from 'react'
import { Platform } from 'react-native'
import { isFidelity } from '@gs/core'
import { downloadFile } from '@gs/core/api'
import { useAppSelector } from '@/store'
import { attachmentDetail, attachmentFolder, attachmentPath, attachmentsOf, openSentence, openStateOfFailure, type Attachment, type Notice, type OpenState } from './notices'

type Sharing = { isAvailableAsync: () => Promise<boolean>; shareAsync: (url: string, options?: { mimeType?: string; dialogTitle?: string; UTI?: string }) => Promise<void> }

/** Lazily, and never on web: a module that is missing from a build is "nothing to open it with", not a crash. */
function sharing(): Sharing | null {
  if (Platform.OS === 'web') return null
  try {
    const mod = require('expo-sharing') as Partial<Sharing> | undefined
    return mod && typeof mod.shareAsync === 'function' && typeof mod.isAvailableAsync === 'function' ? (mod as Sharing) : null
  } catch {
    return null
  }
}

/** iOS wants a Uniform Type Identifier to pick the right viewer; the three kinds the estate can attach have one. */
const UTI: Record<string, string> = { 'application/pdf': 'com.adobe.pdf', 'image/jpeg': 'public.jpeg', 'image/png': 'public.png' }

export type AttachmentRow = {
  key: string
  name: string
  /** "PDF · 240 KB" */
  detail: string
  /** For a screen reader: what it is, and what pressing does. */
  label: string
  busy: boolean
  /** What happened the last time it was pressed — null while there is nothing to say. */
  note: { text: string; tone: 'slate-600' | 'amber-700' } | null
  open: () => void
}

export function useAttachments(notice: Notice | null): AttachmentRow[] {
  const online = useAppSelector((s) => s.network.online)
  const [states, setStates] = useState<Record<string, OpenState>>({})
  const busy = useRef(new Set<string>())

  const noticeId = notice ? String(notice.id) : null
  const files = useMemo<Attachment[]>(() => (notice ? attachmentsOf(notice) : []), [notice])

  const open = useCallback(
    async (file: Attachment) => {
      const key = String(file.id)
      // The board's row is a picture of a row: in the fidelity harness it fetches nothing.
      if (isFidelity || noticeId === null || busy.current.has(key)) return
      busy.current.add(key)
      const say = (state: OpenState) => setStates((held) => ({ ...held, [key]: state }))
      say({ kind: 'fetching' })
      try {
        const got = await downloadFile({ path: attachmentPath(noticeId, file), folder: attachmentFolder(noticeId, file), name: file.name, online })
        if (!got.ok) return say(got.why === 'unsupported' ? { kind: 'unsupported' } : openStateOfFailure(got.error))

        const share = sharing()
        if (!share || !(await share.isAvailableAsync().catch(() => false))) return say({ kind: 'noViewer' })
        const type = (file.content_type ?? '').trim().toLowerCase()
        // Resolves when the sheet closes — whether the resident opened it, saved it or changed their mind.
        await share.shareAsync(got.uri, { mimeType: type || undefined, dialogTitle: file.name, UTI: UTI[type] })
        say({ kind: 'opened' })
      } catch {
        say({ kind: 'noViewer' })
      } finally {
        busy.current.delete(key)
      }
    },
    [noticeId, online],
  )

  return useMemo(
    () =>
      files.map((file) => {
        const key = String(file.id)
        const state = states[key] ?? { kind: 'idle' }
        const detail = attachmentDetail(file)
        return {
          key,
          name: file.name,
          detail,
          label: `Attachment: ${file.name}${detail ? `, ${detail}` : ''}. Open it`,
          busy: state.kind === 'fetching',
          note: openSentence(state),
          open: () => void open(file),
        }
      }),
    [files, states, open],
  )
}
