/**
 * Where a proven sign-in goes — the same for the emailed code, a restored account and the fingerprint (M50 §3), so the
 * three can never disagree about what comes next.
 *
 *   a panic this account left held or open when it was signed out   straight back to it, where it is seen going (B118)
 *   home, the first time on this phone                                 by way of the permissions explanation
 *   home                                                               into the app, the sign-in screens CLEARED from the
 *                                                                      history (Back from Home must not reach Log in)
 *   claim a unit · await approval                                      that screen
 */

import { router } from 'expo-router'
import { enterApp } from '@gs/core'
import { belongsToSomeoneElse, panicBox, panicNotice } from '@/features/panic/panicRecord'
import { store } from '@/store'
import { permissionsAsked } from './usePermissions'

const NEXT = { claim_unit: '/(auth)/claim-unit', await_approval: '/(auth)/pending', home: '/(tabs)' } as const

export function goAfterSignIn(next: 'claim_unit' | 'await_approval' | 'home'): void {
  if (next === 'home') {
    const held = panicBox().get()
    if (panicNotice(held) && !belongsToSomeoneElse(held, store.getState().session.principal)) return enterApp('/panic' as never)
    return enterApp((permissionsAsked.get() ? NEXT.home : '/permissions') as never)
  }
  router.replace(NEXT[next] as never)
}
