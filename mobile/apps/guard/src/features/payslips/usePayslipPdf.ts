/**
 * "DOWNLOAD PDF" ON THE PAYSLIP SCREEN (M8 §5.3) — without a wage ever being written where anything
 * else on the phone could read it.
 *
 * The board draws the button and the client asked for it plainly: a guard should be able to download
 * their payslip. The standing rule on this screen is the opposite-sounding one — a payslip is never
 * written to shared storage — and both are satisfied by where the file goes:
 *
 *   1. the server renders the PDF and it is fetched with the phone's own token (`downloadFile`),
 *      into the APP'S OWN cache folder, which no other app and no file browser can read;
 *   2. it is handed straight to the system share sheet, so the officer can send it to themselves,
 *      to WhatsApp, to a printer — the OS copies it where they choose, which is their decision;
 *   3. it is deleted immediately afterwards. The file exists for as long as the sharing takes.
 *
 * WHAT IS NEVER DONE: writing it to Downloads, to the media library, or leaving it in the cache to be
 * "opened faster next time". A notice attachment is kept for exactly that reason; a wage is not a
 * notice.
 *
 * Every failure is said in the server's own words, and there is no fake success: on web, or in a
 * build with no file module, the button says so rather than appearing to work.
 */

import { useCallback, useState } from 'react'
import { Platform } from 'react-native'
import { discardDownload, downloadFile } from '@gs/core/api'
import { useAppSelector } from '@/store'

type Sharing = { isAvailableAsync: () => Promise<boolean>; shareAsync: (url: string, options?: { mimeType?: string; dialogTitle?: string; UTI?: string }) => Promise<void> }

/** Lazily, and never on web: a module missing from a build is "nothing to share with", not a crash. */
function sharing(): Sharing | null {
  if (Platform.OS === 'web') return null
  try {
    const mod = require('expo-sharing') as Partial<Sharing> | undefined
    return mod && typeof mod.shareAsync === 'function' && typeof mod.isAvailableAsync === 'function' ? (mod as Sharing) : null
  } catch {
    return null
  }
}

export type PdfState = 'idle' | 'working' | 'done'

export type PayslipPdf = {
  state: PdfState
  /** The server's own sentence when it refused, or ours when the phone cannot do it at all. */
  problem: string | null
  download: (slip: { id: number | string; period_label: string }) => void
}

export const NO_SHARING = 'This phone has nothing to open a PDF with, so the payslip cannot be shared from here. The office can email it to you.'
export const NOT_SUPPORTED = 'Downloading is not available in this version of the app. The office can email your payslip to you.'
export const SHARE_FAILED = 'The share sheet could not be opened, so the payslip was not shared. Nothing was kept on this phone: try again, or the office can email it to you.'

/** `payslip-September-2026.pdf` — a name that reads as what it is when it lands in somebody's inbox. */
export const pdfName = (periodLabel: string): string => `payslip-${periodLabel.replace(/\s+/g, '-')}.pdf`

export function usePayslipPdf(): PayslipPdf {
  const online = useAppSelector((s) => s.network.online)
  const [state, setState] = useState<PdfState>('idle')
  const [problem, setProblem] = useState<string | null>(null)

  const download = useCallback(
    async (slip: { id: number | string; period_label: string }) => {
      setProblem(null)
      setState('working')

      try {
        const share = sharing()

        if (!share || !(await share.isAvailableAsync().catch(() => false))) {
          setProblem(NO_SHARING)

          return
        }

        const got = await downloadFile({
          path: `/payslips/${slip.id}/pdf`,
          folder: 'payslips',
          name: pdfName(slip.period_label),
          online,
        })

        if (!got.ok) {
          setProblem(got.why === 'unsupported' ? NOT_SUPPORTED : got.error.message)

          return
        }

        /*
         * The wage leaves the phone's storage the moment the sheet closes, whatever the officer did with it —
         * AND when the sheet could not open at all (B133). The delete sat after the share, so a sheet that threw
         * left the payslip in the cache until the next sign-out, and the button went back to idle saying nothing.
         */
        try {
          await share.shareAsync(got.uri, { mimeType: 'application/pdf', dialogTitle: `Payslip: ${slip.period_label}`, UTI: 'com.adobe.pdf' })
          setState('done')
        } catch {
          setProblem(SHARE_FAILED)
        } finally {
          await discardDownload(got.uri).catch(() => undefined)
        }

        return
      } finally {
        setState((now) => (now === 'working' ? 'idle' : now))
      }
    },
    [online],
  )

  return { state, problem, download: (slip) => void download(slip) }
}
