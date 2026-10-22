/**
 * The sign-in boards' own data (resident-app-02 Sign Up · -03 Log In; -04 Forgot Password draws Log in's estate),
 * served only in web fidelity mode. Every use sits behind `isFidelity`, in the screens' hooks (useAuthForms).
 *
 * The boards draw a PASSWORD field. The server has no passwords (one-time codes by email), so
 * the live screens put the community code where the boards put the password; the board's own
 * row is drawn only here, so the measurement stays honest about what the design shows.
 */

// The board's address on a domain nobody owns (M42 §6: @email.com reads @example.com, as on Profile).
export const logInFixture = { email: 'andrea.fletcher@example.com', estateName: 'Phoenix Park Village 1' } as const

/**
 * resident-app-02 · Sign Up — the board's state: the name typed, the EMAIL field focused and
 * still empty, the terms ticked. `board` is the copy only the board has: an "invite code" (the
 * server has no invite-code endpoint — the community code is what a committee hands out) and a
 * password row (the server has no passwords).
 */
export const signUpFixture = {
  fullName: 'Andrea Fletcher',
  email: '',
  agreed: true,
  board: {
    note: 'Have an invite code from your community committee? Enter it below to link your account automatically.',
    codeLabel: 'Community invite code',
    codePlaceholder: 'e.g. PPV1-4471',
    passwordLabel: 'Password',
    passwordPlaceholder: 'Create a password',
  },
} as const

/**
 * resident-app-29 · Before we get started — the board draws the three cards BEFORE anything has
 * been asked. The cards' own words are the app's copy (they live in the screen, which is the app's
 * location disclosure); `why` is the board's line for the two cards whose live words differ from it
 * (the user, 7 October: permission text says what the app does) — shown in the measurement only.
 */
export const permissionsFixture = {
  showState: false,
  why: {
    location: "Only used to pinpoint a panic alert while you're using the app — never tracked in the background.",
    camera: 'For photos, video and speech-to-text on incident reports and maintenance requests.',
  },
} as const
