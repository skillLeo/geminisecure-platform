/**
 * DEV ONLY (M45 §3) — the design gallery: every Resident screen, every state, with the design's own content.
 * Only a gallery build (EXPO_PUBLIC_GALLERY=1, a phone) or the web fidelity build (EXPO_PUBLIC_FIDELITY=1) has it.
 * Both variables are inlined at build time, so any other bundle — every release — keeps this redirect and drops the
 * gallery, its list and everything they import; `node tools/design/gallery-proof.mjs` checks a release bundle for them.
 */
import { Redirect } from 'expo-router'
import type { ComponentType } from 'react'

const Gallery: ComponentType | null =
  process.env.EXPO_PUBLIC_GALLERY === '1' || process.env.EXPO_PUBLIC_FIDELITY === '1'
    ? // eslint-disable-next-line @typescript-eslint/no-require-imports
      require('@/gallery/GalleryScreen').default
    : null

export default function GalleryRoute() {
  if (!Gallery) return <Redirect href="/" />
  return <Gallery />
}
