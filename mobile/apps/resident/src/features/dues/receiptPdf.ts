/**
 * "Download PDF" — board resident-app-30's outline button, made true: the receipt the screen shows, as a one-page PDF
 * written ON THE PHONE, for the share sheet (Save to Files, Drive, a PDF viewer, email). The server has no PDF of a
 * receipt; this is the screen's own words, laid out as a document, and nothing else — no figure is worked out here.
 *
 * Pure, so it is checked without a phone: a minimal PDF 1.4 — one A4 page, the PDF standard's own Helvetica and
 * Helvetica-Bold (every viewer has them, nothing is embedded), WinAnsi text so "•", "—", "·" and "’" print as
 * themselves. A character WinAnsi cannot hold prints as "?" rather than breaking the file.
 *
 * THE FILE NAMES ITS CLOCK, THE SCREEN DOES NOT (Hassam, M98): a PDF leaves the estate and may be opened anywhere,
 * years later, so its date and time read "4 Sep 2026, 9:41 AM Jamaica time" (`onTheEstateClock`).
 */

import { toBase64 } from '@/features/visitors/qrPicture'
import { onTheEstateClock, type SuccessView } from './duesLogic'

/** WinAnsiEncoding for the characters past ASCII that the app's words use. */
const WIN_ANSI: Record<string, number> = {
  '€': 0x80, '‚': 0x82, '„': 0x84, '…': 0x85, '‘': 0x91, '’': 0x92, '“': 0x93, '”': 0x94, '•': 0x95, '–': 0x96,
  '—': 0x97, '™': 0x99, ' ': 0x20, '·': 0xb7, '©': 0xa9, '®': 0xae, '°': 0xb0, 'é': 0xe9, 'è': 0xe8, 'á': 0xe1,
}

const byteOf = (ch: string): number => {
  const code = ch.charCodeAt(0)
  if (code >= 0x20 && code < 0x7f) return code
  return WIN_ANSI[ch] ?? (code >= 0xa0 && code <= 0xff ? code : 0x3f)
}

/** A PDF string literal's body: the bytes, with "\", "(" and ")" escaped. */
const literal = (text: string): number[] =>
  Array.from(text).flatMap((ch) => {
    const b = byteOf(ch)
    return b === 0x5c || b === 0x28 || b === 0x29 ? [0x5c, b] : [b]
  })

const ascii = (text: string): number[] => Array.from(text, (ch) => ch.charCodeAt(0) & 0xff)

/** Words to lines of at most `max` characters — a long line wraps between words, never inside one. */
export function wrap(text: string, max: number): string[] {
  const lines: string[] = []
  let line = ''
  for (const word of text.split(/\s+/).filter(Boolean)) {
    if (line && line.length + 1 + word.length > max) {
      lines.push(line)
      line = word
    } else line = line ? `${line} ${word}` : word
  }
  if (line) lines.push(line)
  return lines
}

type Run = { font: 'F1' | 'F2'; size: number; text: string; x: number; y: number }

/** The receipt as text runs on an A4 page (595 × 842 points), top to bottom, in the screen's order. */
export function receiptRuns(view: SuccessView): Run[] {
  const runs: Run[] = []
  let y = 780
  const push = (font: Run['font'], size: number, text: string, gap: number, x = 56) => {
    runs.push({ font, size, text, x, y })
    y -= gap
  }
  push('F2', 20, view.title, 26)
  for (const line of wrap(view.line, 78)) push('F1', 11, line, 16)
  y -= 14
  for (const d of view.details) {
    runs.push({ font: 'F1', size: 11, text: d.label, x: 56, y })
    push('F2', 11, d.moment ? onTheEstateClock(d.value) : d.value, 20, 260)
  }
  if (view.total) {
    y -= 6
    runs.push({ font: 'F2', size: 13, text: view.total.label, x: 56, y })
    push('F2', 13, view.total.value, 26, 260)
  }
  y -= 6
  for (const line of wrap(view.note, 84)) push('F1', 10, line, 14)
  return runs
}

/** The whole file, as bytes: catalog, pages, page, two fonts, the content stream, the cross-reference table. */
export function receiptPdfBytes(view: SuccessView): Uint8Array {
  const content: number[] = []
  for (const run of receiptRuns(view)) {
    content.push(...ascii(`BT /${run.font} ${run.size} Tf ${run.x} ${run.y} Td (`), ...literal(run.text), ...ascii(') Tj ET\n'))
  }
  const objects: number[][] = [
    ascii('<< /Type /Catalog /Pages 2 0 R >>'),
    ascii('<< /Type /Pages /Kids [3 0 R] /Count 1 >>'),
    ascii('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>'),
    ascii('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>'),
    ascii('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'),
    [...ascii(`<< /Length ${content.length} >>\nstream\n`), ...content, ...ascii('\nendstream')],
  ]
  const out: number[] = ascii('%PDF-1.4\n')
  const offsets: number[] = []
  objects.forEach((body, i) => {
    offsets.push(out.length)
    out.push(...ascii(`${i + 1} 0 obj\n`), ...body, ...ascii('\nendobj\n'))
  })
  const xref = out.length
  out.push(...ascii(`xref\n0 ${objects.length + 1}\n0000000000 65535 f \n`))
  for (const at of offsets) out.push(...ascii(`${String(at).padStart(10, '0')} 00000 n \n`))
  out.push(...ascii(`trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`))
  return Uint8Array.from(out)
}

export const receiptPdfBase64 = (view: SuccessView): string => toBase64(receiptPdfBytes(view))
