/**
 * Feature flags for the four hard-stop areas — money, restriction, biometrics, voting — one
 * review-risk switch (`askForDndAccess`), and the TEST card checkout's switch (`testCardCheckout`).
 *
 * Each of the first four stands in for a rule the client has not ruled on. Their default is
 * always the SAFEST behaviour: do not charge, do not restrict, do not store, do not count. A
 * flag is flipped only with the client's written answer, and the comment on each one states
 * the question it stands for. Nothing on the handset, and no server response, can turn one on: they are
 * resolved when the bundle is built and never read from anywhere a phone can reach.
 */
export const flags = {
  /**
   * A supervisor's PIN overriding a failed identity check at clock-in (board 39
   * draws the route).
   *
   * ON — RULED AND BUILT. The client's answer was to build it: a stated PRD requirement,
   * not an option. The PIN is never checked on the handset: it is typed, sent once to
   * `shifts.clock_in_override`, and the SERVER sets and verifies it. Every use is logged,
   * flags the shift record and is reported to the Head of Security.
   */
  supervisorPinOverride: true,

  /**
   * Refuse clock-in on a phone with no fingerprint or face set up.
   *
   * ON — RULED: do not silently start an unconfirmed shift. Block the normal path
   * and route to the supervisor override above. The old behaviour — starting the shift
   * anyway and marking it `method: manual` — is gone from both the app and the handset's
   * clock-in endpoint. A guard is never stranded: the override is always offered.
   */
  requireBiometricsToClockIn: true,

  /**
   * Keep anything derived from a biometric check (a score, a template, a photo).
   * Off: the OS prompt answers yes or no; no image, template or score reaches the app and
   * nothing is sent. The app remembers only that setup step 2 was done and how it ended.
   */
  storeBiometricResult: false,

  /**
   * Amenity bookings in the Resident App (boards 21, 22, 31, 37). On: a booking is a
   * REQUEST the estate decides — the app takes no money (the fee and deposit are shown as the
   * server states them and paid the way dues are), and the server alone says who may book
   * (`may_book`, `booking_refused`). Off: every entry point to bookings disappears and its
   * routes answer "not available at this estate".
   *
   * ON — 23 September 2026 (M8 §4.2, §5.1). It was off "for the pilot" because Phoenix Park had
   * not confirmed it has bookable amenities; the client then reviewed the app and said there was
   * "no place there for booking amenities", which is the confirmation the flag was waiting for.
   * Four built, measured, tested screens appear with this one line.
   *
   * WHICH AMENITIES AN ESTATE HAS IS STILL THE ESTATE'S ANSWER, not this flag's: the list comes
   * from `amenities.index`, so an estate with none shows an empty list rather than a broken screen,
   * and `may_book` / `booking_refused` stay the server's word on who may book what.
   */
  amenityBookings: true,

  /**
   * Ask the guard for **Notification Policy Access** (`ACCESS_NOTIFICATION_POLICY`), so the
   * Guard App's "Safety alerts" channel really does sound through Do Not Disturb.
   *
   * ON — RULED: requesting the DND-access permission sits behind a runtime flag, default on, so
   * that if a store reviewer objects the flag turns it off without a new release.
   *
   * THIS FLAG IS THE REVERSIBLE HALF, AND ONLY THAT HALF. The channel's `bypassDnd` is set from first
   * install whatever this says (links.ts `DND_BYPASS`), because a notification channel's configuration
   * is fixed once a phone has created it. What this switches off is the ASKING: the Guard App's
   * Notifications screen stops offering the row, and nothing anywhere sends a guard to the phone's
   * Do Not Disturb settings. Android then drops the flag in silence, the read-back says `off`, and
   * nothing in the app claims otherwise — exactly the state the resident app ships in.
   *
   * TURNING IT OFF COSTS NO STORE RELEASE. It is JS: set `EXPO_PUBLIC_ASK_DND_ACCESS=off` in the
   * build profile (or change this line), and ship it as an over-the-air update. The permission stays
   * in the Guard App's manifest — a merged manifest is native and only a new binary changes it — and
   * PERMISSIONS.md and STORE_REVIEW_NOTES.md both say what it is for. The Resident App never declares
   * it and never reads this flag.
   */
  askForDndAccess: process.env.EXPO_PUBLIC_ASK_DND_ACCESS !== 'off',

  /**
   * The TEST card checkout in the Resident App (`/dues/test-pay`): a preview of how paying dues by card will
   * look once an estate chooses a card gateway. Dues are still paid by hand (Q-012), and this changes nothing
   * about that: every screen of it says TEST MODE, it accepts ONLY Stripe's published test card numbers (a
   * real one is refused and wiped), it sends nothing and keeps nothing, and its result says no money moved
   * and the balance has not changed.
   *
   * ON — 25 September 2026, the client's request: "make a fake page … where user add cards detail like
   * 4242 4242 4242 4242 and expiry date and cvc just for testing … to present how it will look when the
   * api will be integrated". Off: the two ways into it (How to pay, Add a card) disappear, and the route
   * says card payments are not available. Set `EXPO_PUBLIC_TEST_CARD_CHECKOUT=off` to ship it off.
   */
  testCardCheckout: process.env.EXPO_PUBLIC_TEST_CARD_CHECKOUT !== 'off',

  /**
   * A household's pets in the Resident App: the pills under Profile's "Vehicles", "+ Add pet", and a pet's own screen.
   *
   * OFF — 9 October 2026, the user (M57 §6): "Delete Add pet … Remove every pet row, form, detail screen and field
   * … Do not delete the pets table or any stored pet data. If the client wants it back I want that to be a UI change,
   * not a migration." Off: Profile's section is "Vehicles", no pet is fetched, shown or added, and the pet route goes
   * back to Profile. The server keeps every pet row and its endpoints; turning this on brings it all back unchanged.
   */
  householdPets: false,
} as const

export type FlagName = keyof typeof flags
