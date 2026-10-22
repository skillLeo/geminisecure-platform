import { onBeforeUnmount, ref } from 'vue'

/**
 * THE NOISE A PANIC MAKES ON A DISPATCH SCREEN.
 *
 * A dispatcher does not sit and watch a queue. They have the console open on one
 * monitor and are doing something else, and until now a panic arrived in total
 * silence: the row appeared, went red, and waited to be noticed. The screen was
 * already live — `AlertRaised` pushes over the estate's channel in well under a
 * second — and every bit of that speed was spent on a change nobody was looking
 * at. This is the part that makes somebody look.
 *
 * IT SOUNDS ON `is_urgent`, WHICH THE SERVER ALREADY DECIDES. `AlertRaised::
 * broadcastWith()` sets it for `panic` and `duress` and nothing else, so a gate
 * request or a maintenance alert stays quiet and the siren keeps meaning one
 * thing. The flag rides the broadcast itself, so the alarm starts on the push
 * rather than after the reload it triggers — it does not wait for the row.
 *
 * IT KEEPS SOUNDING UNTIL SOMEBODY ACTS. A single beep is a notification; this
 * is a life-safety surface, so it repeats until the dispatcher silences it or
 * the alert leaves the unacknowledged state. Silencing is per-alert: quieting
 * this panic does not deafen the screen to the next one, which is the failure
 * mode of every mute button that ever got left on.
 *
 * WHY THE TONES ARE SYNTHESISED AND NOT AN MP3. An audio file is a binary in the
 * bundle that has to be served, cached and version-matched, and a dispatcher on
 * a dropped connection would get silence at the exact moment silence is worst.
 * An oscillator has nothing to fetch — it works offline, on first paint, at any
 * volume, and cannot 404.
 *
 * THE BROWSER MAY REFUSE TO MAKE A SOUND, and that has to be visible rather than
 * assumed. Chrome will not let a page play audio until the user has interacted
 * with it, so on a freshly opened tab the AudioContext starts suspended and the
 * first panic would be silent with nothing on screen saying so — which is worse
 * than having no alarm at all, because the dispatcher believes they are covered.
 * `blocked` is exposed for the screen to say "click to enable", and the first
 * click anywhere on the document resumes it.
 */

/** Per-browser, per-dispatcher: whether they have turned the siren off entirely. */
const STORAGE_KEY = 'gs.dispatch.alarm.muted'

const readMuted = () => {
    try {
        return window.localStorage.getItem(STORAGE_KEY) === '1'
    } catch {
        // Private windows, blocked site data and thumbnail capture all throw
        // here. A dispatcher whose storage is unreadable gets the alarm ON,
        // because that is the safe direction to fail in.
        return false
    }
}

const writeMuted = (value) => {
    try {
        window.localStorage.setItem(STORAGE_KEY, value ? '1' : '0')
    } catch {
        // Not being able to remember the preference is not a reason to refuse
        // to apply it for this session.
    }
}

export function usePanicAlarm() {
    /** The browser is refusing to play audio until the page is interacted with. */
    const blocked = ref(false)

    /** Right now, this second, the siren is running. */
    const sounding = ref(false)

    /** The dispatcher has turned the siren off for good, on this browser. */
    const muted = ref(readMuted())

    let ctx = null
    let cycleTimer = null
    let disposed = false

    /** Alert ids already silenced by hand, so a new one still sounds. */
    const silenced = new Set()

    /*
     * WHAT THE SIREN IS SOUNDING FOR (B107). Every alert that raised it and has been neither
     * silenced nor answered — so the siren can stop when the last of them leaves the
     * unacknowledged state, which this docblock always promised and nothing did: `stop()` was
     * reached only by a click or by leaving the page. `null` stands for an urgent push that
     * named no alert; only a click quietens that.
     */
    const ringing = new Set()

    /** What the tab said before a panic took the title over. */
    let originalTitle = null
    let titleTimer = null

    /* ---------------------------------------------------------------- audio */

    const audioContext = () => {
        if (ctx !== null) {
            return ctx
        }

        const Ctor = window.AudioContext || window.webkitAudioContext

        if (!Ctor) {
            return null
        }

        ctx = new Ctor()

        /*
         * THE BROWSER'S ANSWER, KEPT IN STEP (B107). A context starts `suspended` on a tab
         * nobody has touched, and moves to `running` when the first click resumes it — the
         * chips read `blocked` from this rather than from the first panic that tried to sound.
         */
        const context = ctx
        context.onstatechange = () => {
            if (ctx === context) {
                blocked.value = context.state === 'suspended'
            }
        }

        return ctx
    }

    /**
     * One rising-falling two-tone burst, the shape a klaxon makes.
     *
     * A square wave rather than a sine: the harmonics are what carry across a
     * room and through a pair of desk speakers turned down. The gain envelope
     * is ramped rather than switched, because an instantaneous start and stop
     * on a square wave produces a click on every edge that is louder than the
     * tone and sounds like a broken speaker.
     */
    const burst = (context, startAt) => {
        const TONES = [880, 660, 880, 660, 880, 660]
        const LENGTH = 0.28
        const PEAK = 0.7

        TONES.forEach((frequency, index) => {
            const at = startAt + index * LENGTH

            const oscillator = context.createOscillator()
            const gain = context.createGain()

            oscillator.type = 'square'
            oscillator.frequency.setValueAtTime(frequency, at)

            gain.gain.setValueAtTime(0.0001, at)
            gain.gain.exponentialRampToValueAtTime(PEAK, at + 0.012)
            gain.gain.setValueAtTime(PEAK, at + LENGTH - 0.03)
            gain.gain.exponentialRampToValueAtTime(0.0001, at + LENGTH - 0.004)

            oscillator.connect(gain)
            gain.connect(context.destination)

            oscillator.start(at)
            oscillator.stop(at + LENGTH)
        })

        return TONES.length * LENGTH
    }

    const cycle = () => {
        if (disposed || muted.value) {
            return
        }

        const context = audioContext()

        if (context === null) {
            return
        }

        if (context.state === 'suspended') {
            blocked.value = true
            // Try anyway: if the page HAS been interacted with, this resolves
            // and the burst below is audible. If not, `blocked` is already set
            // and the screen is saying so.
            context.resume().then(
                () => {
                    blocked.value = false
                },
                () => {}
            )
        } else {
            blocked.value = false
        }

        const length = burst(context, context.currentTime + 0.02)

        // A handset or a laptop with a motor. Ignored where unsupported.
        if (typeof navigator !== 'undefined' && typeof navigator.vibrate === 'function') {
            try {
                navigator.vibrate([300, 120, 300, 120, 300])
            } catch {
                // Some browsers throw rather than return false when the gesture
                // requirement is unmet. Never let it stop the tone.
            }
        }

        // A second and a half of quiet between bursts. Continuous noise is
        // ignored within about a minute; an interrupted pattern is not, and the
        // gap is what lets a dispatcher talk over it on a radio.
        cycleTimer = setTimeout(cycle, (length + 1.5) * 1000)
    }

    /* ---------------------------------------------------------------- title */

    /*
     * The tab itself shouts, because the dispatcher's console is very often not
     * the window in front. A title that alternates is visible in a taskbar and
     * in a pinned tab, where a red row on a page nobody is looking at is not.
     */
    const takeTitle = () => {
        if (titleTimer !== null) {
            return
        }

        originalTitle = document.title
        let on = true

        titleTimer = setInterval(() => {
            document.title = on ? '🚨 PANIC ALERT' : originalTitle
            on = !on
        }, 700)
    }

    const releaseTitle = () => {
        if (titleTimer === null) {
            return
        }

        clearInterval(titleTimer)
        titleTimer = null

        if (originalTitle !== null) {
            document.title = originalTitle
            originalTitle = null
        }
    }

    /* --------------------------------------------------------------- public */

    const stop = () => {
        sounding.value = false
        clearTimeout(cycleTimer)
        cycleTimer = null
        releaseTitle()
    }

    /**
     * Sound the alarm for an alert.
     *
     * @param {string|number} id  the alert, so silencing this one does not
     *                            silence the next. Omit for an unidentified
     *                            urgent event, which always sounds.
     */
    const raise = (id = null) => {
        if (muted.value || disposed) {
            return
        }

        if (id !== null && silenced.has(String(id))) {
            return
        }

        ringing.add(id === null ? null : String(id))

        if (sounding.value) {
            return
        }

        sounding.value = true
        takeTitle()
        cycle()
    }

    /**
     * The dispatcher has heard it. Quiet for the alerts sounding now — `id` narrows it to one —
     * and a new alert still sounds.
     */
    const silence = (id = null) => {
        const quietened = id === null ? [...ringing] : [String(id)]

        for (const each of quietened) {
            if (each !== null) {
                silenced.add(each)
            }

            ringing.delete(each)
        }

        stop()
    }

    /** The alerts the siren is sounding for, by id — what the screen asks the server about. */
    const ringingIds = () => [...ringing].filter((id) => id !== null)

    /**
     * WHAT THE SERVER SAYS IS STILL UNACKNOWLEDGED, of the alerts this siren is sounding for
     * (B107). Every other one has been acknowledged — in this session or any other — or closed,
     * and stops counting; the siren stops when none is left. An urgent push that named no alert
     * is not the server's to answer, and keeps it going until a click.
     *
     * @param {Array<string|number>} awaiting  ids still awaiting the control room
     */
    const settle = (awaiting) => {
        const still = new Set(awaiting.map(String))

        for (const id of [...ringing]) {
            if (id !== null && !still.has(id)) {
                ringing.delete(id)
            }
        }

        if (ringing.size === 0 && sounding.value) {
            stop()
        }
    }

    /** Turn the siren off, or back on, and remember which on this browser. */
    const setMuted = (value) => {
        muted.value = value
        writeMuted(value)

        if (value) {
            ringing.clear()
            stop()
        }
    }

    /**
     * Ask the browser for permission to make noise, from inside a real click.
     *
     * This is the only thing that clears `blocked`, and it has to be called from
     * a user gesture — which is why the screen draws a button for it rather than
     * this being attempted on mount.
     */
    const enable = () => {
        const context = audioContext()

        if (context === null) {
            return
        }

        context.resume().then(
            () => {
                blocked.value = false
            },
            () => {
                blocked.value = true
            }
        )
    }

    /*
     * Any click anywhere counts as the gesture the browser wanted, so a
     * dispatcher who has used the screen at all is already armed and never sees
     * the prompt. `once` — after the first one there is nothing left to resume.
     */
    const armOnFirstGesture = () => {
        const context = audioContext()

        if (context !== null && context.state === 'suspended') {
            blocked.value = true
            context.resume().then(
                () => {
                    blocked.value = false
                },
                () => {}
            )
        }
    }

    if (typeof document !== 'undefined') {
        document.addEventListener('click', armOnFirstGesture, { once: true })
        document.addEventListener('keydown', armOnFirstGesture, { once: true })
    }

    /*
     * ASKED AT ONCE, NOT AT THE FIRST PANIC (B107). `blocked` was set only by a siren that tried
     * to sound, so a fresh tab — whose browser will refuse audio until it is clicked — showed
     * "Alarm on" right up to the panic that came out silent. The context is made now and its
     * state read: a tab reached by a click inside the console is already allowed to sound; one
     * opened cold says "Enable alarm sound" from the start.
     */
    if (typeof window !== 'undefined') {
        const context = audioContext()

        if (context !== null) {
            blocked.value = context.state === 'suspended'
        }
    }

    onBeforeUnmount(() => {
        disposed = true
        stop()
        document.removeEventListener('click', armOnFirstGesture)
        document.removeEventListener('keydown', armOnFirstGesture)

        if (ctx !== null) {
            ctx.close().catch(() => {})
            ctx = null
        }
    })

    return { blocked, sounding, muted, raise, silence, settle, ringingIds, setMuted, enable }
}
