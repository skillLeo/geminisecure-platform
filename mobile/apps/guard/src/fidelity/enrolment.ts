/**
 * The onboarding boards' own data (guard-app-29 · -17 · -30), served only in web
 * fidelity mode. Every use sits behind `isFidelity`. None of these HR fields or kit
 * items exist on the server — the live screens show what the server does give and say
 * so plainly.
 */

import type { IconName } from '@gs/core'

export type DetailRow = { label: string; value: string }
export type KitItem = { icon: IconName; name: string; detail: string | null }

type OnboardingFixture = { firstName: string; rows: DetailRow[]; deviceName: string; kit: KitItem[]; acknowledged: boolean }

const base = { firstName: 'Marcus', deviceName: 'iPhone', kit: [], acknowledged: false }

export const onboardingFixtures: Record<string, OnboardingFixture | undefined> = {
  'guard-app-29': {
    ...base,
    rows: [
      { label: 'Full name', value: 'Marcus Whyte' },
      { label: 'Date of birth', value: '14 Apr 1994' },
      { label: 'Phone', value: '876 555 0134' },
      { label: 'TRN', value: '••• ••• 812' },
      { label: 'NIS number', value: '••• •••1 947' },
      { label: 'Salary account', value: 'NCB •••• 4402' },
      { label: 'Emergency contact', value: 'Paulette Whyte · Mother' },
    ],
  },
  'guard-app-17': {
    ...base,
    rows: [
      { label: 'Name', value: 'Marcus Whyte' },
      { label: 'PSRA number', value: 'PSRA-004471' },
      { label: 'Assigned site', value: 'Phoenix Park Village 1' },
    ],
  },
  'guard-app-30': {
    ...base,
    rows: [],
    kit: [
      { icon: 'eq-item-4', name: 'Uniform — Large', detail: '2 shirts, 2 trousers' },
      { icon: 'rh-icon', name: 'Radio', detail: 'Unit #14' },
      { icon: 'eq-item-2', name: 'Torch', detail: null },
      { icon: 'eq-item-5', name: 'Company ID card', detail: null },
    ],
    acknowledged: true,
  },
}
