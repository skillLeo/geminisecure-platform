# Store listing — GeminiSecure (Resident App)

Copy each block below into the matching field. Nothing here is a draft: it is the text to publish.
The production address is **`https://geminisl.com`** — the one the app is built against (`EXPO_PUBLIC_API_URL`
without its `/api/v1`), filled in below on 27 September 2026 (M17), each page checked live by `gate:store --release`.

The app's own name is `GeminiSecure` (`apps/resident/app.config.ts`), so the listing carries that name and not
"GeminiSecure Resident": Apple rejects a store name that the home screen does not match.

The listing says, on both stores, in the first paragraph and again in the short description, that the app needs an account from a
participating community. That is deliberate: it is the answer to the "I downloaded it and could not use it"
rejection, and it sets the right expectation for anyone else.

The alert in this app notifies the community's own security team. It is not an emergency service, and no line in
this listing says otherwise (`node tools/store/panic-framing.mjs` reads this file and fails if one does).

**M24 (1 October 2026, D-223): the listing says as little as the stores accept.** The client does not want a
competitor, or anyone without an account, to learn what the product does, and a store listing is as public as a
website. So the text below names the app, says it needs an account from the person's community, says what the
alert button is not, and says how privacy works. It lists no features, names no other app, and links to no page
that describes the product (`/support` and `/apps` are gone and answer 410). The Support URL is the client's own
contact page. The long feature list it replaced is in git history if the client ever wants it back.

Nothing in this listing advertises **amenity bookings**. They ship built but switched off for the pilot
(`packages/core/lib/flags.ts` `amenityBookings`), and a listing that showed a screen the person
cannot reach is exactly what Guideline 2.3.3 is about. Put the bullet, the keyword and the screenshot back on the day
that flag goes on — `gate:store` fails if they go in first.

---

## Apple App Store

### App name · 30 characters max

```
GeminiSecure
```

### Subtitle · 30 characters max

```
Residents of your community
```

### Promotional text · 170 characters max

```
For residents of communities that use GeminiSecure. You need an account from your community to use this app.
```

### Keywords · 100 characters max, comma separated

```
gated community,estate,residents,community
```

### Category

Primary: Lifestyle. Secondary: Utilities.

### Age rating

4+. No advertising. No in-app purchase (dues are paid to the community, not through the store).

### Support URL · Marketing URL · Privacy policy URL

Support URL is the client's own contact page. Marketing URL is optional and is left **blank**. The privacy policy
address resolves, is linked from nowhere on the website, and tells search engines not to list it.

```
https://geminisl.com/contact

https://geminisl.com/legal/privacy
```

### Contact details — the company's own (Q-M33, given 2026-09-21; the email is the client's since M33)

The same pair the server prints on the policy pages (`config/legal.php`), and the same pair both
apps carry on their Help screen (`packages/core/lib/legal.ts`). `npm run gate:contact` fails if the
three ever disagree or if a placeholder comes back. The email is the client's own address: the one
first given was a mailbox on geminisl.com, a domain with no mail server, and the gate now fails if any
geminisl.com mailbox is offered again.

```
gemisecja@gmail.com
Shop #20P, Portmore Town Centre, Portmore, Saint Catherine, Jamaica, West Indies
```

- **Play Console → Store listing → Contact details**: the email above. Website `https://geminisl.com`.
  Phone is optional there and is left **blank** — there is no support telephone number.
- **Play Console → App content → Data deletion**: `https://geminisl.com/account/delete`.
- **App Store Connect → App Information**: the email above as the support contact, and the address
  above wherever a trader or seller address is asked for.
- **App Store Connect → App Review Information** asks for a contact **telephone number** and will not
  accept a blank. It is not published and is not a support line — it is the number Apple calls if a
  reviewer is stuck. The company must give one; see `store/MANIFEST.md` §2.

### Description · 4000 characters max

(The same text is used for Google Play's full description below. Keep the two identical.)

---

## Google Play

### App title · 30 characters max

```
GeminiSecure
```

### Short description · 80 characters max

```
For residents of communities that use it. Needs an account from your community.
```

### Full description · 4000 characters max

```
GeminiSecure is the app for residents of communities that use it.

YOU NEED AN ACCOUNT FROM YOUR COMMUNITY
This app works only for households in a participating community. Your estate office confirms your home before your account opens. If your community has not asked you to use this app, it will do nothing for you.

THE ALERT BUTTON
The alert button tells your community's own security team that you need help. It is not an emergency service: it does not reach the police, the fire service or an ambulance. In an emergency, call 119. The app never blocks a call.

YOUR PRIVACY
There is no advertising and there are no trackers. Your location is used only when you send an alert or report an incident, and goes with that alert or report automatically, never in the background. You can delete your account in the app, under Profile, or on the web without the app.
```

### Category and declarations

- Category: House & Home (or Lifestyle).
- Contains ads: No. In-app purchases: No — dues are paid to the community through its own payment provider, for physical services, which is outside Play Billing.
- Target audience: 18 and over.
- Data safety form: fill it from `PRIVACY_DECLARATIONS.md` — the same document the iOS privacy labels come from.
- Content rating questionnaire: no publicly visible user-to-user content, no violence, no gambling, location is collected for the app's function, users can share photos within their own household's requests only.

---

## Assets for this listing

| Field | File |
|---|---|
| App Store marketing icon 1024×1024 | `store/icons/resident/ios/icon-1024.png` |
| Play Console icon 512×512 | `store/icons/resident/android/play-icon-512.png` |
| Play feature graphic 1024×500 | `store/feature-graphic/resident-1024x500.png` |
| iPhone 6.7" screenshots | `store/screenshots/resident/ios-6.7/` |
| iPhone 6.5" screenshots | `store/screenshots/resident/ios-6.5/` |
| Android phone screenshots | `store/screenshots/resident/android-phone/` |
