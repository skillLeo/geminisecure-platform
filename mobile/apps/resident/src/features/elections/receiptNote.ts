/**
 * What the server said when the paper went in — and ONLY that: which ballot, and the DAY.
 * (The server's answer "names no mark, option or receipt", and records a date, never a time.)
 *
 * Memory only, so the receipt screen can show the day straight after casting. It is deliberately
 * a separate file from the marks: nothing here can hold a choice, and the receipt screen reads
 * this and never the marks.
 */

export type ReceiptNote = { ballot: string; votedOn: string | null }

let note: ReceiptNote | null = null

export const receiptNote = {
  set: (next: ReceiptNote): void => {
    note = next
  },
  for: (ballot: string | undefined): ReceiptNote | null => (note !== null && ballot !== undefined && note.ballot === ballot ? note : null),
  clear: (): void => {
    note = null
  },
}
