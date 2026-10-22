# STORE_COMPLIANCE — every App Store and Google Play requirement, how it is met, and where

One row per requirement. **Where** is a path you can open; **State** is honest:

| | |
|---|---|
| **Done** | built, in the repo, and held there by a gate or a test |
| **Human step** | the code is finished; a person has to do something in a console, on a phone, or with a camera |
| **Waits on client** | blocked on something only Gemini Security can supply (`store/MANIFEST.md` §2 lists each with the action) |
| **In progress** | being built now — named here rather than left out |

The automated check is `npm run gate:store` (`tools/gates/store.mjs`), which runs inside `npm run gates`.
`npm run gate:store:release` adds the checks that need a live site (`GS_PUBLIC_URL`).

Policy moves. Before submitting, re-read Apple's App Review Guidelines and the Play Developer Policy Center,
diff them against this file, and record what changed here.

The app this file is about is **GeminiSecure** (the Resident App, `jm.geminisecure.resident`), publicly listed
on both stores.

---

## The requirements

| # | Requirement | How it is met | Where | State |
|---|---|---|---|---|
| 1 | **Distribution model** | Publicly listed on the App Store and Google Play. A community's residents cannot be asked to enrol their phones in device management to get a community app | this file | **Done** |
| 2 | **Reviewer Mode** — server-flagged, demo tenant, isolation tested | A `demo` tenant flag on the server, with the sign-in path that lets a reviewer in without a real account. Tenant isolation applies to it like any other tenant | `app/Models/Tenant.php` (`$demo`), `app/Services/Reviewer/ReviewerMode.php`, `app/Api/AppMatrix.php`, `app/Http/Controllers/Api/V1/Resident/AuthController.php`, `app/Console/Commands/DemoSeed.php`, `tests/Feature/ReviewerModeTest.php` | **Done.** Isolation is not merely claimed: the test walks a REAL estate's own row ids with the reviewer token across the endpoints and proves each answers exactly as an id nobody has — status and error code alike — and withdrawing the flag stops every bypass mid-test. It is a SERVER flag on one account, never a build flag, so the binary under review is the binary that ships |
| 3 | **Resident demo path on the sign-in screen** | "Explore a demo community" enters the demo tenant read-only. Every write is refused by the route's own ability middleware, before any controller — the token carries no write ability at all, so this is not hidden buttons. A test fires all 27 resident write endpoints with that token and asserts each is refused | same package, plus `tests/Feature/ReviewerModeTest.php` §4 | **Done** |
| 4 | **`STORE_REVIEW_NOTES.md`** | Written, with the demo credentials it quotes. It is what goes into App Review Information and into Play's "app access" answer | `STORE_REVIEW_NOTES.md` in the repo root | **Done** |
| 5 | **Account deletion, in-app, two taps, itemised retention** | The row is labelled **"Delete my account"**, two taps from a tab: Profile → Delete my account (and again under Settings). The screen itemises what goes now and what is kept, and why, before the person confirms. Server: `DELETE /api/v1/me`, which requires the person to type the word the screen showed them, so a mis-tap cannot do it | `apps/resident/src/features/more/screens/ProfileScreen.tsx` → `apps/resident/app/settings/delete-account.tsx` → `DeleteAccountScreen.tsx`; `app/Api/Catalogue.php` `me.delete_account` | **Done** |
| 6 | **Web deletion page, reachable without the app** | `GET /account/delete`, public, no sign-in | `routes/public.php`, `App\Http\Controllers\PublicPagesController::deleteAccount` | **Done** (code) · **Waits on client** (live domain) |
| 7 | **`PERMISSIONS.md` complete; every permission justified** | One row per permission, with the feature, the screen that asks, and whether refusing locks anything. The list of what is actually in the merged manifest is generated, not typed | `PERMISSIONS.md`, `tools/store/manifest-audit.mjs`, `store/manifests/resident.permissions.json` | **Done** |
| 8 | **Unused permissions removed from the *merged* manifest** | Broad media and storage, contacts, overlay, `WRITE_SETTINGS`, phone state and Wi-Fi state are blocked; background location and every foreground service are blocked too, and a config plugin strips the location foreground-service element a library declares. **Held on purpose, and defended in `PERMISSIONS.md`:** `RECORD_AUDIO` (speech to text on the phone, M12 §2.9) | `apps/resident/app.config.ts` `android.permissions` / `blockedPermissions`, `tools/expo-plugins/withoutLocationService.cjs`, `store/manifests/resident.permissions.json` | **Done** — `gate:store` fails on any permission with no row in `PERMISSIONS.md` |
| 9 | **Prominent disclosure before every sensitive request** | A "Before we get started" screen states what is used and why, and the phone's own prompt appears only when that row is tapped. Nothing is asked at launch, no dialog is raised from a `useEffect` on mount, and refusing blocks only the feature that needs it | `apps/resident/src/features/auth/screens/PermissionsScreen.tsx` | **Done** |
| 10 | **Background location** | **None.** The manifest blocks `ACCESS_BACKGROUND_LOCATION`, and iOS is asked only for When In Use, so Play shows no declaration form | `apps/resident/app.config.ts` | **Done** |
| 11 | **Foreground services** | **None.** Every foreground service is blocked in the merged manifest, and `gate:store` check 2 fails if one appears | `apps/resident/app.config.ts`, `gate:store` check 2 | **Done** |
| 14 | **Panic framed correctly everywhere** (Apple 5.1.5) | The alert is described as reaching the community's own security team, never anything else, in the app *and* in the store listing — and a gate reads the copy to keep it that way | `tools/store/panic-framing.mjs` (reads `apps/resident/**` and `store/**`), `apps/resident/src/features/panic/screens/PanicScreen.tsx`, `store/listing/resident.md` | **Done** |
| 15 | **A working call to the real number, offline** | `Call 119 — police` is a plain `tel:` link on the alert screen in every phase. A call needs no data | `apps/resident/src/features/panic/usePanic.ts` (`EMERGENCY_NUMBER`) | **Done** — the gate fails if it disappears |
| 16 | **Biometrics: consent, on-device only, no SDK capturing frames** | The optional app lock is the phone's own check (`expo-local-authentication`); no image is captured, kept or sent, and no library receives a camera frame | `tools/gates/camera-frames.mjs`, `PRIVACY_DECLARATIONS.md`, purpose strings in `apps/resident/app.config.ts` | **Done** (code) · **Waits on client** (Jamaican legal review of the consent wording) |
| 17 | **Apple privacy manifest, all SDKs audited** | `PrivacyInfo.xcprivacy`, generated from one description: no tracking, no tracking domains, what is collected, and the union of required-reason APIs found by walking the real `node_modules` | `tools/store/privacy-manifests.cjs`, `tools/store/privacy-manifest.mjs --check`, `apps/resident/app.config.ts` `ios.privacyManifests` | **Done** |
| 18 | **`PRIVACY_DECLARATIONS.md` generated from code** | Derived from the code paths that collect each item; both stores' forms are filled from it, so they cannot disagree | `PRIVACY_DECLARATIONS.md`, `tools/store/privacy-declarations.mjs --check`, `tools/store/data-map.cjs` | **Done** (document) · **Human step** (typing it into both consoles) |
| 19 | **Legal URLs live and linked in-app without sign-in** | The privacy policy, the terms and the account deletion page are served by the platform itself; their addresses are derived from the one configured server address, so there is no second value to keep in step. Linked under the Sign Up tick box, on Privacy & Data and on Help | `routes/public.php`, `packages/core/lib/legal.ts`, `gate:store --release` loads each one | **Done** (routes + links) · **Waits on client** (live domain, final text — the pack to send the attorney is `LEGAL_REVIEW_PACK.md`) |
| 19b | **A real support contact on every page and in the app** (Q-M33, given 2026-09-21; the email is the client's own since M33, the first one being on geminisl.com, which takes no mail) | `gemisecja@gmail.com` and Shop #20P, Portmore Town Centre, Portmore, Saint Catherine, Jamaica, West Indies. Config DEFAULTS, so a deployment with no env file still serves them: the Contact section of the policy and the terms, the store listing, and the app's Help screen (compiled in, so it reads with no signal). No telephone number is invented — the client gave none | `config/legal.php`, `packages/core/lib/legal.ts`, `store/listing/resident.md`, `gate:contact`, `tests/Feature/PublicPagesTest.php` | **Done** — `gate:contact` fails if a placeholder ever comes back or the copies disagree. Apple's App Review Information form takes the callback telephone the client gave (`store/MANIFEST.md` §2 row 3b) |
| 20 | **Export compliance** | `ITSAppUsesNonExemptEncryption: false` set explicitly, so no upload stops to ask. The reasoning behind that answer: standard cryptography only — HTTPS, and the Ed25519 signature a visitor pass carries, which is authentication, not encryption of content | `apps/resident/app.config.ts` `ios.config.usesNonExemptEncryption` | **Done** (value set) · **Waits on client** (the legal determination is theirs) |
| 21 | **iOS purpose strings, specific, naming the app** | Every `NS*UsageDescription` written as a sentence naming the app and the exact reason; the plugins are given the same text so a default cannot replace it | `apps/resident/app.config.ts` `infoPlist` and the plugin options | **Done** — `gate:store` fails on a generic or missing string |
| 22 | **Android: app bundle, no unused permissions, pickers not broad access** | `production` builds an app bundle; photos come through the system photo picker; there is no contacts permission | `apps/resident/eas.json`, `PERMISSIONS.md` | **Done** |
| 23 | **Store assets generated from the real app** | Listing text for both stores; icons at every size and the Play feature graphic, generated from the design system's brand mark and theme tokens; screenshots taken from the real app at three device classes, on a showcase community of invented names — no demonstration labels, no developer names (§C) | `store/` (see `store/MANIFEST.md`), `tools/store/brand-assets.mjs`, `tools/store/screenshots.mjs` | **Done** |
| 24 | **`gate:store` passing** | `npm run gate:store`, and it is inside `npm run gates`: permissions, foreground services, purpose strings, export compliance, privacy manifests and declarations, panic framing, account deletion within two taps, placeholder copy, development flags in a release profile, the safety invariants, the legal pages (with `--release`), the store assets, the listing not advertising a feature that does not ship, and the store pack carrying nothing but this app | `package.json`, `tools/gates/store.mjs` | **Done** |
| 24a | **The listing may not promise what the build does not have** (Guideline 2.3.3) | Amenity bookings (`flags.amenityBookings`, off for the pilot) are in neither the listing nor the screenshots, and `gate:store` fails if they go back first | `tools/gates/store.mjs`, `store/listing/resident.md`, `store/MANIFEST.md` §4 | **Done** |
| 25 | **Release builds, both platforms** | Nothing in the repo blocks them: `eas.json` profiles are correct and `app.config.ts` refuses a production build that is not pointed at an HTTPS API | `apps/resident/eas.json`, `apps/resident/app.config.ts` | **Waits on client** — an Expo account and signing credentials |
| 26 | **This document** | — | `STORE_COMPLIANCE.md` | **Done** |
| 27 | **iOS Time Sensitive notifications** — the entitlement a panic push needs to cut through a Focus mode | The server sends `interruptionLevel: 'time-sensitive'` on every urgent push, the gate approval request among them; iOS honours it only for an app carrying `com.apple.developer.usernotifications.time-sensitive`, and the app declares it. Justification: **a visitor waiting at the gate for the resident's answer is the textbook Time Sensitive case** — it grants nothing but the right to mark a notification urgent, and the person can still switch it off | `apps/resident/app.config.ts` (`ios.entitlements`); `PERMISSIONS.md` §"iOS entitlements" | **Done** (declared) · **Human step** — tick **Time Sensitive Notifications** on the App ID in the Apple Developer portal and reissue the provisioning profile, or the signed build is rejected at upload. Steps in `PERMISSIONS.md` |
| 28 | **An Android "safety" channel that claims only what it can keep** | The "Safety alerts" channel is created **without** a Do Not Disturb bypass, and the app does not declare `ACCESS_NOTIFICATION_POLICY`: a homeowner silencing their own phone is their own choice. The app never *claims* a bypass — `safetyChannelDnd()` reads the channel back out of Android and reports what the platform actually has. It keeps the highest importance an app can set, its own vibration and public lock-screen visibility | `packages/core/notify/runtime.ts`, `packages/core/notify/links.ts`, `packages/core/notify/__tests__/channels.test.ts`, `PERMISSIONS.md` | **Done** |

---

## C. Store assets — what is done and what a person still has to do

Everything is under `store/`, with `store/MANIFEST.md` as its index and its list of what is missing.

**Done, in the repo:**

- **Listing text** for both stores — name, subtitle, short description, full description, keywords — each
  inside its store's character limit, and cut to the minimum the stores accept (M24). The listing says, in
  the first paragraph and again in the short description, that the app needs an account from a participating
  community. That sentence is the answer to the commonest rejection the app would get: a reviewer who
  downloads it, cannot sign up, and rejects under Guideline 2.1 or 4.2.
- **Icons at every size** Apple and Google ask for, plus the Android adaptive layers and the monochrome
  themed-icon layer — generated from the design system's own brand mark over the app's own splash gradient,
  with colours read from `packages/core/theme/tokens.json`. No placeholder, no hand-drawn artwork, no colour
  literal.
- **The icon files the app ships were the Expo starter logo** until they were replaced. They now carry the
  GeminiSecure mark (`node tools/store/brand-assets.mjs --apply`), so the icon in the listing and the icon on
  the phone are the same image.
- **The Play feature graphic**, 1024×500, from the theme's gradient and the Poppins wordmark the design
  system already uses.
- **Screenshots from the real app** — the same react-native-web build the fidelity harness diffs against
  the design boards, at 1290×2796, 1242×2688 and 1080×1920, six screens, full of realistic content rather
  than empty lists.
- **Two screens were taken back out**, and this is the part worth remembering: a design board can draw a
  feature the build does not have. Board 21 (Book an amenity) draws a feature switched off for the pilot,
  and board 11 (New maintenance request) draws a microphone mid-dictation. Either picture could have promised
  something the reviewer cannot reach — Guideline 2.3.3. `gate:store` checks 12 and 13 refuse both.
- **The alert screen is not in the set.** A picture of it, without the sentences that surround it in the
  app, is the easiest way for a reviewer to read the product as something it is not. The listing says in
  words what the alert is and what it is not; a screenshot cannot.

**Human step — retake the screenshots on a handset.** They are browser captures of the demonstration
estate. Re-run `node tools/store/screenshots.mjs` against it, on a handset when one is reachable, so that
what the listing shows and what the reviewer sees when they sign in are the same product. `store/MANIFEST.md`
§3 has the steps. This is recorded as a note by `gate:store`, not passed silently.

---

## D. What is not done, stated plainly

- **Reviewer Mode is built** and rows 2–4 are Done. Two defects were found in it before it was accepted, and
  both would have caused a rejection on their own: deleting the demonstration account (which both stores put
  on their compliance checklist) silently destroyed the credentials for the NEXT reviewer, so Apple would
  have seen the right thing and Play, a week later, would have found the door gone; and seeded data expired
  before Apple's first look, which is routinely a day or two after submission. Both are fixed and tested.
- **One reviewer-facing gap is still open:** on the read-only demonstration path, a write the reviewer
  attempts is refused with a developer's sentence about tokens and abilities rather than a plain one about a
  demonstration account. Survivable, but it is the wrong thing to be relying on. Fix it and re-check this row
  before submission.
- **Release builds** for the stores have not been produced. Nothing in the repo blocks them; an Expo account
  and signing credentials do.
- **The legal pages are served but their words are not final.** `gate:store --release` fails while the
  draft banner is still on them.
- **No app preview video.** Neither store requires one, and a video goes stale on every screen change. If
  the client wants one, it is a separate piece of work.
- **Policy re-check before submission** has not been done, because it must be done on the day: re-read both
  policy sources, diff them against this file, and record the differences here.
