/**
 * DEV ONLY — renders a screen exactly as lifted from its board, so the lifter's own
 * fidelity can be measured for all 40 screens (`npm run fidelity -- --lifted`).
 * The registry is only required when EXPO_PUBLIC_FIDELITY=1; that variable is inlined
 * at build time, so a production bundle drops the branch and every lifted file with it.
 */
import { Redirect, useLocalSearchParams } from 'expo-router'
import type { ComponentType } from 'react'

const registry: Record<string, () => ComponentType> =
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  process.env.EXPO_PUBLIC_FIDELITY === '1' ? require('@/lifted').lifted : {}

export default function LiftedRoute() {
  const { id } = useLocalSearchParams<{ id: string }>()
  const load = id ? registry[id] : undefined
  if (!load) return <Redirect href="/" />
  const Screen = load()
  return <Screen />
}
