# `store/` — what is here, what is missing, and who has to supply it

Everything a console asks for, generated from this repo, for the one app that goes to the stores:
**GeminiSecure** (the Resident App, `jm.geminisecure.resident`).
`STORE_COMPLIANCE.md` (repo root) is the compliance side of the same submission; this file is only the assets.

Regenerate the lot with `npm run store:assets`. `npm run gate:store` fails if any of it is missing.

---

## 1. What is here

| What | Where | Made by | State |
|---|---|---|---|
| Listing text, both stores | `store/listing/resident.md` | written, checked against each field's character limit, cut to the minimum the stores accept (M24) | **Done** |
| App icons, every size Apple and Google ask for | `store/icons/resident/ios/`, `store/icons/resident/android/`, `store/icons/resident/web/` | `node tools/store/brand-assets.mjs` | **Done** |
| Android adaptive icon layers (foreground, background, monochrome) | `store/icons/resident/android/adaptive-*.png` | same | **Done** |
| Google Play feature graphic, 1024×500 | `store/feature-graphic/resident-1024x500.png` | same | **Done** |
| The icon files the app actually ships | `apps/resident/assets/images/{icon,android-icon-foreground,android-icon-background,android-icon-monochrome,splash-icon,favicon}.png` | `node tools/store/brand-assets.mjs --apply` | **Done.** They were the Expo starter logo until then — that is a placeholder and a third party's mark |
| Screenshots, three device classes | `store/screenshots/resident/<device>/` | `node tools/store/screenshots.mjs` | **Done — from the DEMONSTRATION ESTATE.** See §3 |
| What each screenshot is, why it was chosen, how it was taken and when | `store/screenshots/manifest.json` | same | **Done** |
| The permissions the merged manifest really holds | `store/manifests/resident.permissions.json` | `node tools/store/manifest-audit.mjs` | **Done** |
| Release notes | `store/release-notes.md` | written | **Done** |

### Where the icons come from

Not drawn for the store. Every icon is the design system's own splash lockup: the brand mark from
`packages/core/ui/BrandMark.tsx` — two circles, r 12.5, centres at cx 15 and cx 25, the right one
`mix-blend-mode: multiply`, which Foundation says must never be redrawn — over the app's own splash
gradient from `packages/core/theme/gradients.ts`, in colours read from `packages/core/theme/tokens.json`
at run time. There is no colour literal in the generator and no hand-drawn artwork anywhere in `store/`.

The marketing icons are square, opaque and un-rounded, which is what both stores require — they round
the corners themselves. Only the Android adaptive foreground and the monochrome (themed-icon) layer carry
transparency, which is what those two layers are for.

### Device classes the screenshots are taken at

| Class | Pixels | Taken at | Asked for by |
|---|---|---|---|
| `ios-6.7` | 1290 × 2796 | 430 × 932 at 3× | App Store — the required display set |
| `ios-6.5` | 1242 × 2688 | 414 × 896 at 3× | App Store — still accepted, and the fallback for older listings |
| `android-phone` | 1080 × 1920 | 360 × 640 at 3× | Google Play — exactly 9:16, inside Play's 320–3840 px limits and over its 1080 px bar |

The viewport is the phone's logical size and the scale factor its real density, so each picture is the
layout that phone actually gets, at the exact pixel size the console accepts. Nothing is stretched.

---

## 2. What is still missing, and who has to supply it

| # | Missing | Who | What exactly they must do |
|---|---|---|---|
| 1 | ~~The live HTTPS address~~ — **`https://geminisl.com`** | Done | Filled in the listing (M17). Every URL in the listing text and in the app is derived from it (`packages/core/lib/legal.ts`) |
| 2 | Final privacy-policy and terms text | **Client + Jamaican lawyer** | The pages and their addresses exist; the words in them are the company's. **The pack to send the attorney is written: `LEGAL_REVIEW_PACK.md` in the repository root** — every clause quoted in full, what it is trying to say, and the questions to put to them. When they sign it off, set `LEGAL_TEXT_STATUS=final` on the server; `gate:store --release` refuses a submission until the DRAFT banner is gone |
| 3 | ~~Support email and postal address~~ — **given 2026-09-21 (Q-M33)**; the email is the client's own since M33 | Us | `gemisecja@gmail.com` · Shop #20P, Portmore Town Centre, Portmore, Saint Catherine, Jamaica, West Indies. In `config/legal.php` as the DEFAULT (not an env blank), so the policy pages and the app's Help screen carry them; also in `store/listing/resident.md`. The address first given was a mailbox on geminisl.com, which takes no mail. Held by `npm run gate:contact` |
| ~~3b~~ | ~~An **App Review contact telephone number**~~ | **Given 2026-09-21** | **`1-876-460-5520`.** Enter it in App Store Connect → App Review Information → Contact Information, and nowhere else. It is Apple's number to call a reviewer back on, not a support line, so it must never appear on a public page, in the listing or in the app |
| 4 | Apple Developer Program enrolment, as an **organisation**, with a D-U-N-S number | **Client** | An individual account shows a person's own name as the seller |
| 5 | Google Play Console account, as an **organisation** | **Client** | A personal account is forced through a closed test with a minimum number of testers over a minimum period before production access — that is weeks of delay |
| 6 | Expo (EAS) account and the production build credentials | **Client** | Needed before a release build can be made |
| 7 | ~~Screenshots retaken on the reviewer/demo tenant~~ — **done 2026-09-21** | Us | Retaken from the demonstration estate (§3). What is still outstanding is retaking them **on a handset**, which needs a reachable device (M6 §4) |
| 8 | App preview videos | **Optional, client** | Neither store requires one. Skipped deliberately: a video is a listing asset that goes stale on every screen change |

---

## 3. The screenshots come from the demonstration estate — read this before submitting

**They were retaken on 2026-09-21 (M6 §3.2).** They are no longer board fixtures. `node tools/store/
screenshots.mjs` signs in to the **demonstration estate** through the app's own front door, with the
credentials printed in `STORE_REVIEW_NOTES.md` — signed in as `reviewer@demo.geminisecure.app` with the
static six-digit code — and photographs what a reviewer will find. Same estate, same account, same names,
same figures. That was the one thing wrong with the first cut, and it is the thing that is itself a
rejection.

**Before regenerating**, on the platform the app is built against:

1. `php artisan demo:seed` (or `--confirm-live` off a developer's machine). It is idempotent and it
   REFRESHES what goes stale, so run it first, every time.
2. `node tools/store/screenshots.mjs` with the Metro server up. It takes six pictures at three device sizes
   each and refuses to photograph a screen that has not got the estate's data on it.

**What the harness does that is worth knowing.** On web a token is held in memory only and never in
localStorage (`packages/core/auth/tokenStorage.ts`, deliberate), so a page reload signs the session out:
each device signs in ONCE and then moves between screens the way the app does, through the history. Every
screen names a sentence that proves the estate's own data is on it, and the picture is not taken until that
sentence is there — so an empty screen fails the run instead of being quietly submitted.

**One of the six is a form and was typed into before the picture — and nothing was sent.** The visitor
invitation: a photograph of an empty form demonstrates nothing, so the demonstration estate's own words are
typed through the app's real inputs and the form is not submitted. No pass was issued.

**These are BROWSER captures, and the moment a handset answers they should be retaken on it** (M6 §3.2, §4).
No device was reachable for this run. Device captures are better evidence and serve the phone-check and the
listing at once.

There is no shortcut here that is honest. A mocked-up screenshot of a screen the reviewer cannot reach is
exactly what Guideline 2.3.3 is written about.

`--source fixtures` still takes the old board-fixture pictures, for fidelity work. Those copies say so in
`manifest.json` and `gate:store` refuses them.

---

## 4. The screens chosen, and why

Six, in upload order. The first three answer the three questions a reviewer asks — what does it do, who is
it for, does it work — because on both stores the first two or three are all most people see.

Home · invite a visitor · visitor tracking · notices · dues · track a maintenance request.

Two screens were ruled out for the same reason, and both are worth re-reading before anyone puts them back:

- **"Book an amenity" (board 21)**, although it is one of the best-looking in the set: bookings ship switched
  OFF for the pilot (`packages/core/lib/flags.ts` `amenityBookings`), and a listing picture of a screen the person
  cannot reach is exactly what Guideline 2.3.3 is written about. Put it back the day the flag goes on —
  `gate:store` fails if the words or the picture go in first.
- **"New maintenance request" (board 11)**, whose board state draws the microphone mid-dictation. A picture of
  a listening microphone promises more than a reviewer can reach on a review device. Board 26 — the same
  request being tracked to done — is shown instead, and it is the better picture anyway.

The alert screen is deliberately **not** in the set. A picture of it, without the sentences that surround it
in the app, is the easiest way for a reviewer to read the product as something it is not. The listing text
says in words what the alert is and what it is not; a screenshot cannot.

---

## 5. Order of work in each console

**App Store Connect** — create the app record (bundle id `jm.geminisecure.resident`) → paste the listing text
from `store/listing/resident.md` → upload `store/icons/resident/ios/icon-1024.png` → upload the `ios-6.7`
screenshots, then `ios-6.5` → privacy labels from `PRIVACY_DECLARATIONS.md` → App Review Information from
`STORE_REVIEW_NOTES.md`, including the demo account → upload the build → submit.

**Google Play Console** — create the app → Main store listing: title, short and full description from
`store/listing/resident.md`, `store/icons/resident/android/play-icon-512.png`, the feature graphic, and the
`android-phone` screenshots → Data safety from `PRIVACY_DECLARATIONS.md` → content rating questionnaire →
target audience → app access: "all functionality is restricted", with the demo credentials from
`STORE_REVIEW_NOTES.md` → upload the app bundle → internal testing → production.
