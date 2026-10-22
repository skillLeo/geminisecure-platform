/**
 * The scanner boards' own data (guard-app-04 valid pass · guard-app-28 override on an
 * expired pass), served only in web fidelity mode. Every use sits behind `isFidelity`.
 */

import type { GateVerdict } from '@/features/gate/verdict'

type ScanFixture = { verdict: GateVerdict; reason: string }

/** guard-app-32 · Manual Pass Code — the code the board draws typed in. */
export const manualCodeFixture = { code: 'PPV1-4471' } as const

export const scanFixtures: Record<string, ScanFixture | undefined> = {
  'guard-app-04': {
    reason: '',
    verdict: {
      source: 'online',
      code: 'valid',
      tone: 'green',
      headline: 'VALID PASS · SINGLE VISIT',
      detail: null,
      admitAllowed: true,
      overrideAllowed: false,
      pass: { id: '7d0c1b52-3f0e-4c58-9a51-5b8f2f6a4471', visitorName: 'Marcia James', category: 'single', purpose: 'Family visit', plate: null, validFrom: '2026-09-04T19:00:00Z', validTo: '2026-09-04T21:00:00Z', singleUse: true },
      unit: 'Lot 47',
      household: 'Andrea Fletcher',
    },
  },
  'guard-app-28': {
    reason: "Visitor confirmed by phone with Andrea Fletcher, pass just wasn't renewed in time",
    verdict: {
      source: 'online',
      code: 'expired',
      tone: 'red',
      headline: 'EXPIRED PASS',
      detail: 'This pass expired at 1:00 PM. Admitting now requires a note — your supervisor will be notified.',
      admitAllowed: false,
      overrideAllowed: true,
      pass: { id: '2a9e77c0-84d1-4b3a-b0f6-91c3d2e80128', visitorName: 'Kevin Chambers', category: 'single', purpose: null, plate: null, validFrom: '2026-09-04T16:00:00Z', validTo: '2026-09-04T18:00:00Z', singleUse: true },
      unit: 'Lot 47',
      household: 'Andrea Fletcher',
    },
  },
}
