/**
 * The settings screens' rules, held to what matters on them:
 *   - "Clear data" removes COPIES only — never the queue, a draft, an alert in progress, the session or a choice;
 *   - what is still waiting to be sent is counted and SAID before anything is cleared;
 *   - the emergency numbers are 119 and 110, and nothing about them is conditional;
 *   - a stored preference from an older version, or edited by hand, never breaks the app;
 *   - help can be searched, and says what the app really does.
 */
import { flags, getStorage } from '@gs/core/lib'
import { CATEGORIES, DEFAULTS as NOTIFY_DEFAULTS, OFFERED_CATEGORIES } from '../../notify/prefs'
import { DEFAULTS, TEXT_SIZES, appLockOn, bindTextScale, normalise, prefs, settingsPrefs, stepTextSize, textScaleOf, textSizeAt, wired } from '../prefs'
import { AWAY_MS, DRAFTS_KEY, EMERGENCY, PERMISSION_WORD, TOPICS, canAskPhone, clearWarning, clearedSentence, copiesIn, draftsIn, signOutWarning, lockName, lockOnReturn, lockOnStatus, permissionOf, permissionTapHint, phoneRow, savedCopies, searchHelp, signInLine, sinceShort, sizeWords, telLink, versionLine, waitingIn } from '../settingsLogic'

describe('Saved copies opens what it describes (M12 §2.11)', () => {
  it('names each copy for what it is, adds up its size, and lists nothing that is not a copy', () => {
    const rows = savedCopies([
      { key: 'v1:cache:passes.all', bytes: 3000 },
      { key: 'v1:cache:passes.epass', bytes: 900 },
      { key: 'v1:cache:notices.index', bytes: 2500 },
      { key: 'v1:cache:notices.older', bytes: 600 },
      { key: 'v1:cache:me.household', bytes: 800 },
      { key: 'v1:cache:something.new', bytes: 10 },
      { key: 'v1:outbox', bytes: 99_999 }, // what is waiting to be sent is not a copy
      { key: 'v1:passes.drafts', bytes: 500 },
    ])
    expect(rows).toEqual([
      { label: 'Your e-Pass', bytes: 900, copies: 1 },
      { label: 'Your visitor passes', bytes: 3000, copies: 1 },
      { label: 'Estate notices', bytes: 3100, copies: 2 },
      { label: 'Your household and balance', bytes: 800, copies: 1 },
      { label: 'Other screens', bytes: 10, copies: 1 },
    ])
    expect(savedCopies([])).toEqual([])
  })

  it('says a size in plain words', () => {
    expect(sizeWords(10)).toBe('under 1 KB')
    expect(sizeWords(3100)).toBe('3 KB')
    expect(sizeWords(2_500_000)).toBe('2.4 MB')
  })
})

describe('clearing what is saved on this phone', () => {
  const keys = [
    'v1:cache:me.show',
    'v1:cache:visitor_passes.index',
    'v1:cache:dues.pay_intent.last',
    'outbox:ops',
    'v1:passes.drafts',
    'v1:panic.active',
    'v1:principal',
    'v1:pref:last_estate',
    'v1:settings.prefs',
    'v1:notifications.prefs',
    'v1:prefs.dataLight',
    'v1:notify.snapshot',
  ]

  it('removes the copies of server answers and nothing else', () => {
    expect(copiesIn(keys)).toEqual(['v1:cache:me.show', 'v1:cache:visitor_passes.index', 'v1:cache:dues.pay_intent.last'])
  })

  it('never lists the queue, a draft, an alert in progress, the session or a preference', () => {
    const doomed = copiesIn(keys)
    for (const kept of ['outbox:ops', 'v1:passes.drafts', 'v1:panic.active', 'v1:principal', 'v1:settings.prefs', 'v1:notifications.prefs', 'v1:prefs.dataLight']) expect(doomed).not.toContain(kept)
  })

  it('counts only what has not reached the estate', () => {
    expect(waitingIn([{ state: 'pending' }, { state: 'syncing' }, { state: 'confirmed' }, { state: 'refused' }])).toBe(2)
  })

  it('says what is waiting BEFORE clearing, and that it is kept', () => {
    const calm = clearWarning({ waiting: 0, online: true })
    expect(calm.lines).toHaveLength(1)
    const warned = clearWarning({ waiting: 2, online: true })
    expect(warned.lines.join(' ')).toMatch(/2 things you saved with no connection are still waiting to be sent/)
    expect(warned.lines.join(' ')).toMatch(/NOT cleared/)
    expect(clearWarning({ waiting: 1, online: true }).lines.join(' ')).toMatch(/1 thing you saved with no connection is still waiting/)
  })

  it('warns that clearing with no connection leaves the passes and lists empty', () => {
    const offline = clearWarning({ waiting: 0, online: false })
    expect(offline.title).toMatch(/no connection/)
    expect(offline.lines.join(' ')).toMatch(/EMPTY until you reconnect/)
  })

  it('reports the outcome honestly', () => {
    expect(clearedSentence({ removed: 4, waiting: 0, online: true })).toBe('Cleared. The app fetches fresh copies as you open each screen.')
    expect(clearedSentence({ removed: 0, waiting: 0, online: false })).toBe('There was nothing saved to clear. Reconnect to fetch fresh copies.')
    expect(clearedSentence({ removed: 4, waiting: 3, online: true })).toMatch(/3 things you saved with no connection are still waiting to be sent, untouched\.$/)
  })
})

describe('signing out of this phone', () => {
  it('with nothing to lose, says what goes and calls the button what it is', () => {
    const calm = signOutWarning({ unsent: 0, drafts: 0, online: true, blocked: false })
    expect(calm.lines).toHaveLength(1)
    expect(calm.confirmLabel).toBe('Sign out')
  })

  it('counts out loud what ONLY this phone has: things waiting to be sent AND invitations still in draft', () => {
    const warned = signOutWarning({ unsent: 2, drafts: 1, online: false, blocked: false })
    expect(warned.lines.join(' ')).toMatch(/2 things you saved with no connection have NOT been sent yet: you are offline\. Signing out deletes them\./)
    expect(warned.lines.join(' ')).toMatch(/1 invitation is still a draft on this phone: no pass was made for it\. Signing out deletes it too\./)
    expect(warned.confirmLabel).toBe('Sign out and delete them')
    expect(signOutWarning({ unsent: 0, drafts: 1, online: true, blocked: false }).confirmLabel).toBe('Sign out and delete it')
    expect(signOutWarning({ unsent: 0, drafts: 3, online: true, blocked: false }).lines.join(' ')).toMatch(/3 invitations are still drafts/)
  })

  it('a blocked sign-out never offers to delete anything', () => {
    expect(signOutWarning({ unsent: 1, drafts: 0, online: false, blocked: true }).confirmLabel).toBe('Sign out')
  })

  it('reads the drafts as they are stored, and anything unreadable as none', () => {
    expect(DRAFTS_KEY).toBe('v1:passes.drafts')
    expect(draftsIn(JSON.stringify([{ id: 'a' }, { id: 'b' }]))).toBe(2)
    expect(draftsIn('[]')).toBe(0)
    expect(draftsIn(null)).toBe(0)
    expect(draftsIn('{not json')).toBe(0)
    expect(draftsIn('{"a":1}')).toBe(0)
  })
})

describe('emergency numbers', () => {
  it('are 119 for the police and 110 for fire and ambulance', () => {
    expect(EMERGENCY.map((e) => e.number)).toEqual(['119', '110'])
    expect(EMERGENCY.map((e) => telLink(e.number))).toEqual(['tel:119', 'tel:110'])
  })

  it('carry no field that could switch one off', () => {
    for (const e of EMERGENCY) expect(Object.keys(e).sort()).toEqual(['key', 'label', 'number'])
  })

  it('a tel: link keeps digits and a leading plus only', () => {
    expect(telLink('(876) 555-0100')).toBe('tel:8765550100')
    expect(telLink('+1 876 555 0100')).toBe('tel:+18765550100')
  })
})

describe('help', () => {
  it('covers the four topics the board names', () => {
    expect(TOPICS.map((t) => t.title)).toEqual(expect.arrayContaining(['Visitors & gate access', 'Dues & payments', 'Elections & voting', 'Using the panic button']))
  })

  it('says the true things: dues are paid by hand, a ballot is never saved for later, a draft admits nobody', () => {
    const all = TOPICS.flatMap((t) => t.articles.map((art) => art.a)).join(' ')
    expect(all).toMatch(/paid by hand/)
    expect(all).toMatch(/never saved to be sent later/)
    expect(all).toMatch(/cannot enter on a draft/)
    expect(all).toMatch(/119/)
    expect(all).toMatch(/110/)
  })

  it('narrows to the articles that mention every word typed', () => {
    const found = searchHelp('pay hand')
    expect(found.map((t) => t.key)).toEqual(['dues'])
    expect(found[0]?.articles).toHaveLength(1)
    expect(searchHelp('')).toBe(TOPICS)
    expect(searchHelp('zebra crossing')).toEqual([])
  })

  it('ignores case and punctuation', () => {
    expect(searchHelp('AUTOPAY?').map((t) => t.key)).toEqual(['dues'])
  })
})

/*
 * Bookings are off for the pilot: a flagged-off feature must be neither reachable NOR
 * advertised, and a switch with nothing behind it is a dead control. Both checks read the flag
 * rather than hard-coding false, so the day it is turned on they say the opposite and still pass.
 */
describe('nothing in settings offers a feature this build has switched off', () => {
  it('the Notifications screen has no switch for bookings while bookings are off', () => {
    // The stored preference keeps its key whatever the flag says — only the ROW comes and goes.
    expect(CATEGORIES.map((cat) => cat.key)).toContain('bookings')
    expect(typeof NOTIFY_DEFAULTS.bookings).toBe('boolean')

    expect(OFFERED_CATEGORIES.some((cat) => cat.key === 'bookings')).toBe(flags.amenityBookings)
    expect(OFFERED_CATEGORIES.length).toBe(CATEGORIES.length - (flags.amenityBookings ? 0 : 1))
    // Every row that IS offered still has a default, so no switch is drawn from undefined.
    for (const cat of OFFERED_CATEGORIES) expect(typeof NOTIFY_DEFAULTS[cat.key]).toBe('boolean')
  })

  it('no help answer names booking an amenity while bookings are off', () => {
    const words = TOPICS.flatMap((t) => t.articles.flatMap((art) => [art.q, art.a])).join(' ')
    expect(/book(?:ing)? an amenity|amenity booking/i.test(words)).toBe(flags.amenityBookings)
    // …and the answer it belongs to still reads as a sentence, with the rest of it intact.
    expect(searchHelp('what needs a connection')[0]?.articles[0]?.a).toMatch(/^Signing in, inviting a visitor, answering the gate, [a-z]/)
  })
})

describe('who this is and which version', () => {
  it("names the server's masked address, never more", () => {
    expect(signInLine({ channel: 'email', destination_hint: 'a•••@example.com' })).toBe('You sign in with a one-time code sent to a•••@example.com.')
    expect(signInLine(null)).toBe('You sign in with a one-time code sent to your email address.')
  })

  it('shows the version the build reports, or says there is none', () => {
    expect(versionLine('0.1.0')).toBe('Version 0.1.0')
    expect(versionLine('0.1.0', '14')).toBe('Version 0.1.0 (14)')
    expect(versionLine(null)).toBe('Version not reported by this build')
  })

  it("calls the phone's lock what its owner calls it", () => {
    expect(lockName([2], 'ios')).toBe('Face ID')
    expect(lockName([1], 'ios')).toBe('Touch ID')
    expect(lockName([1], 'android')).toBe('fingerprint')
    expect(lockName([1, 2], 'android')).toBe('fingerprint or face unlock')
    expect(lockName([], 'android')).toBe("your phone's screen lock")
  })
})

describe('the app lock: when the cover comes down', () => {
  it('COLD START: decided when the session leaves booting: the stored choice cannot be read any earlier', () => {
    expect(lockOnStatus('booting', 'active', true)).toBe(true)
    expect(lockOnStatus('booting', 'active', false)).toBe(false)
    // Nothing has changed yet, so nothing is decided: the first render, behind the splash, must not settle it.
    expect(lockOnStatus('booting', 'booting', true)).toBeNull()
  })

  it('nobody signed in means nothing to cover, and a sign-in during this launch is proof enough', () => {
    expect(lockOnStatus('booting', 'signedOut', true)).toBe(false)
    expect(lockOnStatus('signedOut', 'active', true)).toBe(false)
    expect(lockOnStatus('signedOut', 'pending', true)).toBe(false)
  })

  it('no other change of status lifts or drops the cover', () => {
    expect(lockOnStatus('pending', 'active', true)).toBeNull()
    expect(lockOnStatus('active', 'signedOut', true)).toBeNull()
    expect(lockOnStatus('active', 'active', true)).toBeNull()
  })

  it('locks again after a minute or more away: only if the app was really left, and only if chosen', () => {
    expect(lockOnReturn(1_000, 1_000 + AWAY_MS, true)).toBe(true)
    expect(lockOnReturn(1_000, 1_000 + AWAY_MS - 1, true)).toBe(false)
    expect(lockOnReturn(null, 9_999_999, true)).toBe(false)
    expect(lockOnReturn(1_000, 1_000 + AWAY_MS, false)).toBe(false)
  })
})

describe("the phone's own permissions", () => {
  it('"Not asked" is said only when the phone says so: a refusal is not called that', () => {
    expect(permissionOf({ granted: false, canAskAgain: true, status: 'undetermined' })).toBe('notAsked')
    expect(permissionOf({ granted: false, canAskAgain: true, status: 'denied' })).toBe('refused')
    expect(permissionOf({ granted: false, canAskAgain: false, status: 'denied' })).toBe('blocked')
    expect(permissionOf({ granted: true, canAskAgain: true, status: 'granted' })).toBe('granted')
    expect(permissionOf(null)).toBe('unsupported')
    expect(PERMISSION_WORD.refused).toBe('Not allowed')
  })

  it('a tap asks while the phone will still ask, opens its Settings once it will not, and is no control where there is nothing to ask', () => {
    expect(canAskPhone('notAsked')).toBe(true)
    expect(canAskPhone('refused')).toBe(true)
    expect(canAskPhone('blocked')).toBe(false)
    expect(canAskPhone('granted')).toBe(false)
    expect(permissionTapHint('notAsked')).toMatch(/Asks the phone/)
    expect(permissionTapHint('blocked')).toMatch(/phone's settings/)
    expect(permissionTapHint('unsupported')).toBeNull()
  })
})

describe('preferences kept on this phone', () => {
  it('start off, at the standard size', () => {
    expect(normalise(null)).toEqual(DEFAULTS)
    expect(DEFAULTS).toEqual({ textSize: 'standard', reduceMotion: false, highContrast: false, appLock: false, paymentsLock: true })
  })

  it('an unknown size or a non-boolean is ignored, not trusted', () => {
    expect(normalise({ textSize: 'enormous', reduceMotion: 'yes', appLock: 1 })).toEqual(DEFAULTS)
    expect(normalise({ textSize: 'larger', reduceMotion: true, appLock: true })).toEqual({ textSize: 'larger', reduceMotion: true, highContrast: false, appLock: true, paymentsLock: true })
    // Require biometrics for payments: on unless it was turned off (board 19 draws it on, M50 §3.3).
    expect(normalise({ paymentsLock: false }).paymentsLock).toBe(false)
  })

  it('a choice is kept, read back, and gone when a sign-out wipes the phone: with no stale copy in memory', async () => {
    settingsPrefs.set({ textSize: 'large', reduceMotion: true })
    expect(settingsPrefs.get()).toEqual({ textSize: 'large', reduceMotion: true, highContrast: false, appLock: false, paymentsLock: true })
    expect(prefs()).toMatchObject({ textSize: 'large', reduceMotion: true, appLock: false, dataLight: false, notifications: { gate: true, panic: true } })
    await getStorage().clearAll()
    expect(settingsPrefs.get()).toEqual(DEFAULTS)
  })

  it('the lock is not promised until its cover is really mounted', () => {
    expect(wired).toEqual({ appLock: false })
  })

  it('the type layer is given the size now, on every change, and never after it lets go', () => {
    const seen: number[] = []
    const stop = bindTextScale((scale) => seen.push(scale))
    settingsPrefs.set({ textSize: 'largest' })
    stop()
    settingsPrefs.set({ textSize: 'standard' })
    expect(seen).toEqual([1, 1.5])
  })

  it('a choice written to the phone behind the splash is seen as soon as someone is told to look again', async () => {
    // What a cold start looks like: the stored text appears underneath, with no `set` from this module.
    const seen: boolean[] = []
    const stop = settingsPrefs.subscribe(() => seen.push(appLockOn()))
    expect(appLockOn()).toBe(false)
    getStorage().set('v1:settings.prefs', JSON.stringify({ appLock: true }))
    settingsPrefs.reset()
    stop()
    expect(seen).toEqual([true])
    await getStorage().clearAll()
    expect(appLockOn()).toBe(false)
  })

  it('text size steps between its ends and never past them, and is NOT capped to protect a layout (M50 §6.5)', () => {
    expect(stepTextSize('standard', -1)).toBe('standard')
    expect(stepTextSize('standard', 1)).toBe('large')
    expect(stepTextSize('largest', 1)).toBe('largest')
    expect(textScaleOf('standard')).toBe(1)
    // The old steps stopped at 1.3× "so every screen still fits": the largest now goes past it, and screens scroll.
    expect(textScaleOf('largest')).toBe(1.5)
    expect(TEXT_SIZES.map((size) => size.scale)).toEqual([1, 1.15, 1.3, 1.5])
  })

  it('high contrast is kept on this phone, off unless chosen (M50 §6.5)', () => {
    expect(DEFAULTS.highContrast).toBe(false)
    expect(normalise({ highContrast: true }).highContrast).toBe(true)
    expect(normalise({ highContrast: 'yes' as never }).highContrast).toBe(false)
  })

  it('lists each signed-in phone by the name it gave, this one first and never with a Sign out of its own', () => {
    const at = Date.parse('2026-09-26T12:00:00Z')
    const day = (iso: string) => iso.slice(0, 10)
    const base = { signed_in_at: '2026-09-25T19:00:00Z', timed_out: false }
    // Model codes read as a person reads them (M51-A §3); a code not known is kept; an emulator says so.
    expect(phoneRow({ ...base, id: 1, device: 'SM-G991U1', platform: 'android', last_used_at: '2026-09-26T12:00:00Z', current: true }, at, day)).toEqual({ id: 1, name: 'Samsung Galaxy S21', detail: 'This phone · signed in 2026-09-25 · in use now', current: true, canSignOut: false })
    expect(phoneRow({ ...base, id: 2, device: 'SM-S938B', platform: 'android', last_used_at: '2026-09-26T11:55:00Z', current: false }, at, day)).toEqual({ id: 2, name: 'Samsung Galaxy S25 Ultra', detail: 'Signed in 2026-09-25 · last used 5 min ago', current: false, canSignOut: true })
    expect(phoneRow({ ...base, id: 5, device: 'sdk_gphone64_x86_64', platform: 'android', last_used_at: '2026-09-26T11:00:00Z', current: false }, at, day).name).toBe('An Android emulator')
    expect(phoneRow({ ...base, id: 6, device: 'SM-X999Z', platform: 'android', last_used_at: '2026-09-26T11:00:00Z', current: false }, at, day).name).toBe('SM-X999Z')
    // A sign-in from before phones named themselves, and one idle past the window, which still gets notifications.
    const old = phoneRow({ ...base, id: 3, device: null, platform: 'ios', last_used_at: '2026-09-26T09:00:00Z', current: false, timed_out: true }, at, day)
    expect(old.name).toBe('An iPhone')
    expect(old.detail).toContain('3 hours ago')
    expect(old.detail).toContain('still gets your notifications')
    // This phone with no name on the server takes the model the phone reports.
    expect(phoneRow({ ...base, id: 4, device: null, platform: 'android', last_used_at: null, current: true }, at, day, 'Pixel 8').name).toBe('Pixel 8')
    expect(sinceShort('2026-09-24T12:00:00Z', at, day)).toBe('2026-09-24')
    expect(sinceShort(null, at, day)).toBeNull()
  })

  it('a finger on the text-size track lands on the nearest step, and past either end on that end', () => {
    // A 217-wide track with a 17-wide thumb: the thumb's centre runs from 8.5 to 208.5, the steps a third apart.
    expect(textSizeAt(8.5, 217, 17)).toBe('standard')
    expect(textSizeAt(75, 217, 17)).toBe('large')
    expect(textSizeAt(145, 217, 17)).toBe('larger')
    expect(textSizeAt(208.5, 217, 17)).toBe('largest')
    expect(textSizeAt(-40, 217, 17)).toBe('standard')
    expect(textSizeAt(900, 217, 17)).toBe('largest')
    // Not yet measured: the first step, never a division by nothing.
    expect(textSizeAt(50, 0, 17)).toBe('standard')
  })
})
