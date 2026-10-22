/**
 * The boards' own data, served only in web fidelity mode so a screen can be put in
 * exactly the state its board draws. Every use sits behind `isFidelity`.
 */

/** resident-app-01 · Splash */
export const bootFixture = {
  /** `.splash .loader i{width:62%}` */
  loaderProgress: 0.62,
} as const
