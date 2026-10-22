/**
 * THE APPS' FRONT DOOR (M32 F10, as M47 §1.4 corrected it).
 *
 * Someone who already has an account is the common case, every day; creating one happens once. So the app opens on
 * Log in. Each screen's way to the other is THE BOARD'S OWN LINE OF TEXT — "Don't have an account? Sign up", "Already
 * have an account? Log in" — not the full-width outline button M32 F10 put there: the design has no such button
 * (M47 §1.4), and the line is a real control with a tap area widened to the kit's. The estate chosen last time is
 * remembered and preselected on both. (The screens themselves are mounted in `authScreens.test.tsx`.)
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

const read = (...parts: string[]) => readFileSync(path.join(__dirname, '..', ...parts), 'utf8')
const logIn = read('screens', 'LogInScreen.tsx')
const signUp = read('screens', 'SignUpScreen.tsx')
const forms = read('useAuthForms.ts')
// Where the splash hands over lives in its hook since the splash was rebuilt on the tokens (M50 §7).
const splash = readFileSync(path.join(__dirname, '..', '..', 'boot', 'useSplash.ts'), 'utf8')

describe('the app opens on Log in', () => {
  it('sends every signed-out phone to Log in, a brand-new one included', () => {
    const route = splash.slice(splash.indexOf('export function nextRoute'), splash.indexOf('export function waitsForBrand'))

    expect(route).toContain("return '/(auth)/log-in'")
    expect(route).not.toContain('sign-up')
  })
})

describe('each screen reaches the other by the board\'s own line', () => {
  it('Log in ends with "Don\'t have an account? Sign up", and draws no button for it', () => {
    expect(logIn).toContain(`<AuthFooter words="Don't have an account? " link="Sign up" onPress={m.signUp}`)
    expect(logIn).not.toMatch(/label="Create an account"/)
    expect(logIn.indexOf('label="Log in"')).toBeLessThan(logIn.indexOf('<AuthFooter'))
  })

  it('Create account ends with "Already have an account? Log in", and draws no button for it', () => {
    expect(signUp).toContain('<AuthFooter words="Already have an account? " link="Log in" onPress={m.logIn}')
    expect(signUp).not.toMatch(/label="I already have an account/)
    expect(signUp.indexOf('label="Create account"')).toBeLessThan(signUp.indexOf('<AuthFooter'))
  })

  it('goes to the other screen, not back through the stack', () => {
    expect(forms).toContain("signUp: () => router.replace('/(auth)/sign-up' as never)")
    expect(forms).toContain("logIn: () => router.replace('/(auth)/log-in' as never)")
  })
})

describe('the estate chosen last time', () => {
  it('is preselected on both screens', () => {
    expect(forms.match(/rememberedEstate\?\.id/g)?.length).toBeGreaterThanOrEqual(2)
  })
})
