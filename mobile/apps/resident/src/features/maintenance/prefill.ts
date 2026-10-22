/**
 * "Report it again": the title of a request travels from the status screen to the new-request form in
 * memory — never in a URL (a link can be shared, logged, or left in a browser's history).
 * `peek` does not consume, so a development double-render reads the same thing twice; the form clears it
 * once it has mounted.
 */

export type RequestPrefill = { title: string }

let held: RequestPrefill | null = null

export const requestPrefill = {
  set: (value: RequestPrefill): void => {
    held = value.title.trim() ? { title: value.title.trim() } : null
  },
  peek: (): RequestPrefill | null => held,
  clear: (): void => {
    held = null
  },
}
