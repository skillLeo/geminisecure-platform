import type { SignOutModel } from './useProfile'

/**
 * What the sign-out question says. The sentences live here; auth/__tests__/signOut.test.ts and
 * profile/__tests__/signOutSheet.test.tsx read them. (Sign out itself is on Settings, board 18 — Profile, board 17,
 * draws none.)
 */
export function signOutWords(o: SignOutModel): { title: string; lines: string[]; warning: { lead: string; items: string[]; more: number } | null; confirmLabel: string; cancelLabel: string; blocked: string | null } {
  const changes = `${o.unsent} change${o.unsent === 1 ? '' : 's'}`
  const lines = ["Everything the app keeps on this phone is removed. To come back you'll log in again with a new code sent to your email."]
  // Said BEFORE the tap, not claimed afterwards (M6, Q-M42): with no connection this phone cannot tell the server to
  // end the session. It is signed out of THIS phone either way, and the session on the server closes by itself
  // inside the thirty-minute idle window.
  if (!o.blocked && !o.online) lines.push('There is no connection, so the estate cannot be told to end this session now. This phone is signed out either way, and the session ends by itself within 30 minutes.')
  return {
    title: 'Sign out of this phone?',
    lines,
    warning:
      !o.blocked && o.unsent > 0
        ? { lead: `${changes} saved on this phone ${o.unsent === 1 ? 'has' : 'have'} not reached the estate yet${o.online ? '' : ': there is no connection'}. Signing out now deletes ${o.unsent === 1 ? 'it' : 'them'} for good:`, items: o.unsentShown, more: o.unsentMore }
        : null,
    confirmLabel: !o.blocked && o.unsent > 0 ? `Sign out and delete ${changes}` : 'Sign out',
    cancelLabel: 'Stay signed in',
    blocked: o.blocked,
  }
}
