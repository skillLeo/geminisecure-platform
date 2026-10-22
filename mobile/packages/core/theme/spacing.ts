/**
 * Spacing rhythm and radius — lifted from Foundation (tokens.json), plus the
 * radii the mobile boards draw that Foundation's sheet does not list.
 *
 * Wireframe px are treated as pt 1:1 (397 → 393 is 0.99); there is no scaling
 * function, and a board's fixed px is never turned into a percentage.
 */

import tokens from './tokens.json'

/** 4 · 8 · 12 · 16 · 20 · 26 · 30 */
export const spacing = tokens.spacing

export const radius = {
  /** Foundation */
  urlBar: tokens.radii['7'].px,
  control: tokens.radii['10'].px,
  input: tokens.radii['12'].px,
  card: tokens.radii['16'].px,
  modal: tokens.radii['20'].px,
  screen: tokens.radii['48'].px,
  pill: tokens.radii['100'].px,
  /** Mobile boards (recorded per board in _design/UI_KIT_SPEC.md) */
  pillButton: 11,
  mobileInput: 14,
  mobileButton: 15,
  sheet: 22,
  authHeader: 34,
} as const

/** Layout constants the boards share. The OS owns the status bar and the home indicator. */
export const layout = {
  /** Design canvas; the build base width is 393pt and px are treated as pt 1:1. */
  canvasWidth: 397,
  canvasHeight: 856,
  /** Board status bar height — used ONLY as the top inset in web fidelity mode. */
  boardStatusBar: 54,
  /** Tab bar content; the board's 86 = 60 + the bottom inset (26 on the board). */
  tabBarContent: 60,
  boardBottomInset: 26,
  /** In-screen header content below the top inset (board: 100 incl. the 54 status bar). */
  headerContent: 46,
  /** Nothing tappable under this. */
  minTouchGuard: 48,
  minTouchResident: 44,
  /**
   * The least room between the phone's own status bar, camera cut-out or gesture bar and anything an app draws (M57 §2):
   * the spacing scale's 8, always added to the device's inset, never a fixed status-bar height.
   */
  safeGap: tokens.spacing[1] as number,
} as const
