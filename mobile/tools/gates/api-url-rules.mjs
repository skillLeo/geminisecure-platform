/**
 * ONE RULE, IN ONE PLACE: an app's config must REFUSE a production build that has no https server address.
 *
 * It was written twice — once in `one-api-url.mjs` and once as rule 8 of `store.mjs` — and both copies
 * insisted on the exact spelling `EAS_BUILD_PROFILE === 'production'`. When the configs were widened to
 * `startsWith('production')`, so that the internal-distribution `production-apk` profile is guarded by the
 * same refusal (M7 §14.5), both gates failed an app that checks MORE builds than they asked for. Two copies
 * of a rule are two chances to be wrong about it, so there is now one, and both gates import it.
 *
 * What the rule actually requires: the config tests the build PROFILE, and refuses unless the address
 * matches an `^https:` pattern. It deliberately does not care which of the two spellings is used, or what
 * the message says — only that a production build cannot be made against a laptop address.
 */

/** Either spelling of the profile test, followed by an `^https:` pattern within a couple of hundred characters. */
const REFUSES = /EAS_BUILD_PROFILE[\s\S]{0,120}(===\s*'production'|startsWith\('production'\))[\s\S]{0,200}\^https:/

/** True when this app.config.ts source refuses a production build without an https server address. */
export function refusesProductionWithoutHttps(source) {
  return REFUSES.test(String(source ?? ''))
}
