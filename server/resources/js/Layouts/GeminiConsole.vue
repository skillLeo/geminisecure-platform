<script setup>
import { computed, ref, onMounted, onBeforeUnmount, watch } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { useWireframe } from '../composables/useWireframe'
import { useConsolePanic } from '../composables/useConsolePanic'
import { startLive } from '../live/index.js'
import BrandMark from '../Components/BrandMark.vue'
import LiveStatus from '../Components/LiveStatus.vue'
import ModuleIcon from '../Components/ModuleIcon.vue'
import PanicOverlay from '../Components/PanicOverlay.vue'
import ConsoleAvatar from '../Components/ConsoleAvatar.vue'
import ProfilePhotoDialog from '../Components/ProfilePhotoDialog.vue'
import ToastStack from '../Components/ToastStack.vue'
import BackLink from '../Components/BackLink.vue'
import { toast } from '../lib/toasts.js'

/**
 * The Gemini Console shell.
 *
 * DOM and class names are the board's, unchanged, and the stylesheet is lifted
 * verbatim from those boards — so nothing here may introduce a class the board
 * does not define or a style rule of its own.
 *
 * THE TOPBAR IS PER SCREEN, NOT FIXED.
 *
 * This layout used to render the Platform Dashboard's topbar on every page:
 * title, search, notification bell, profile chip. The boards do not agree with
 * that. Cross-tenant reports draws a title and nothing else. Access & audit log
 * draws a title and an Export button. Guard workforce draws a title, a search
 * field with its OWN placeholder ("Search guards…"), and an Add guard button.
 * Only the dashboard draws the bell and the chip.
 *
 * So the topbar is assembled from what each screen passes: `searchRoute` opts
 * a screen into a live search field, and the `actions` slot fills .top-right.
 * A screen that passes neither gets a bare title, which is what most boards
 * draw.
 *
 * SIGN-OUT LIVES IN THE SIDEBAR FOOTER, and that is not a preference.
 *
 * The interactivity bar requires sign-out reachable from every authenticated
 * page. The topbar profile chip appears on exactly one board, so hanging
 * sign-out there would have put it on one screen out of forty-five. .side-foot
 * is drawn on every board, so that is where it goes.
 *
 * Navigation is NOT declared here. It arrives from the server, generated from
 * the role access matrix at runtime, so a module a role cannot use is absent
 * from this list entirely rather than rendered and hidden. There is
 * deliberately no `disabled` state to render.
 */
const props = defineProps({
    title: { type: String, required: true },
    board: { type: String, default: 'super-admin-01-login-dashboard-and-activity' },

    /**
     * Where the top-bar search sends its query.
     *
     * Null means the board draws no search field on this screen, and none is
     * rendered — rather than a dead field that swallows what is typed into it.
     */
    searchRoute: { type: String, default: null },
    searchValue: { type: String, default: '' },
    searchPlaceholder: { type: String, default: 'Search clients, guards, invoices…' },

    /**
     * The other words this screen's address carries, sent with the query (M32 F5): its filters, and its table's order
     * and page size — never its page, because a new search starts again from the first. The estate shell's twin.
     */
    searchParams: { type: Object, default: () => ({}) },

    /**
     * For a board that DRAWS a search field this screen cannot yet honour.
     *
     * The Platform Dashboard is the case: it draws a global search box, and
     * there is no global search. Omitting the field would put a hole in the
     * board; rendering a live-looking one that swallows the query would be
     * worse. So it renders, visibly inert, saying why.
     */
    searchDisabledReason: { type: String, default: null },

    /**
     * THE WAY BACK, ON A NESTED PAGE (M95 §3), as the estate shell draws it: `{ href, label }`, "Back to clients", top
     * left before the title. A page the sidebar opens passes none: it is the top of its own section.
     */
    back: { type: Object, default: null },
})

useWireframe(props.board)

const page = usePage()

/*
 * WHAT JUST HAPPENED IS A TOAST, TOP RIGHT, WITH A CROSS (M95 §2; Hassam, 12 October), as the estate shell says it. The
 * server's flash after a press ("Invoice resent.", "Could not reach the gateway.") is said here once, for every Gemini
 * page; no page draws it as a banner of its own. A standing fact about the page or the estate stays a banner on its page,
 * and a refusal of an open dialog stays in that dialog's amber band and is never toasted.
 */
watch(
    () => page.props.flash,
    (flash) => {
        if (!flash) {
            return
        }

        if (flash.success) {
            toast(flash.success, { tone: 'success' })
        }

        if (flash.error) {
            toast(flash.error, { tone: 'error' })
        }

        if (flash.status) {
            toast(flash.status, { tone: 'info' })
        }
    },
    { immediate: true },
)

// Live updates (M11 Part C, DESIGN §6.1): the one socket and the shell's channels, from the server's list,
// brought in line whenever a visit hands over new props. No live prop (signed out): nothing is opened.
startLive(page.props.live)
watch(() => page.props.live, (live) => startLive(live))

const user = computed(() => page.props.auth.user)

/* Site time or my time (M19A §7): the server reads every stamp on the chosen clock; this only asks for the change. */
const timeView = computed(() => page.props.timeView ?? null)
const browserZone = () => {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || null
    } catch {
        return null
    }
}
const myZoneTitle = computed(() => {
    const zone = timeView.value?.zone ?? browserZone()
    return zone ? `Every time on this console on your own clock — ${zone.replace(/_/g, ' ')}` : 'Every time on this console on your own clock'
})
const chooseTimeView = (view) => {
    if (timeView.value?.view === view) return
    router.post('/account/time-view', { view, zone: view === 'mine' ? browserZone() : null }, { preserveScroll: true, preserveState: true })
}
const nav = computed(() => page.props.nav ?? [])

/**
 * The sidebar groups modules under uppercase headings, with anything
 * section-less rendered first and outside any heading.
 */
const ungrouped = computed(() => nav.value.filter((m) => !m.section))

const sections = computed(() => {
    const groups = new Map()

    for (const module of nav.value.filter((m) => m.section)) {
        if (!groups.has(module.section)) {
            groups.set(module.section, [])
        }
        groups.get(module.section).push(module)
    }

    return [...groups].map(([name, modules]) => ({ name, modules }))
})

// The avatar — the person's picture, or their two initials as drawn — is ConsoleAvatar's (M12 §4.8).

/* --- a panic, wherever the reader is (M10 §1.2 items 3 and 4) -------- */

/*
 * MOUNTED ON THE SHELL, WHICH IS THE WHOLE POINT — the estate console's reason,
 * for "the Gemini Director — dashboard alert" and "the Gemini Head of Security
 * for that site — dashboard alert". Every Gemini page renders this component,
 * so a Director reading a pay run is told about a panic at Lot 47 without
 * having a dispatch screen open.
 *
 * `geminiPanicWatch` is decided by the server (`PanicWatch`): null for anybody
 * who may not act on an alert, and otherwise the estates the alert channel will
 * admit them to. The composable is handed nothing else and opens no socket
 * without it.
 *
 * `streamsHere` is the one thing the page knows and the server does not: the
 * dispatch screens pass `estateIds` because they stream those estates and sound
 * the siren themselves, so there the pop-up stands down and only the bar shows.
 */
const panicWatch = computed(() => page.props.geminiPanicWatch ?? null)

const panic = useConsolePanic(panicWatch.value, {
    owner: user.value?.id ?? null,
    streamsHere: (estate) =>
        Array.isArray(page.props.estateIds) && page.props.estateIds.map(String).includes(String(estate)),
})

/* --- search ---------------------------------------------------------- */

const query = ref(props.searchValue)

/*
 * Server-side, and debounced.
 *
 * `preserveState` keeps the field focused and the caret where it was, which a
 * full visit would throw away on every keystroke. `replace` keeps the browser
 * history from filling with one entry per character.
 */
let searchTimer = null

const runSearch = () => {
    if (!props.searchRoute) {
        return
    }

    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => {
        router.get(
            props.searchRoute,
            { ...props.searchParams, q: query.value || undefined },
            { preserveState: true, preserveScroll: true, replace: true }
        )
    }, 250)
}

/* --- sign out -------------------------------------------------------- */

const menuOpen = ref(false)

/*
 * THE SIDEBAR ON A PHONE, as the estate shell draws it (369e87ef; Hassam, 12 Oct: check the Gemini shell at 390 px, where
 * it kept its full sidebar and pushed the page sideways). Below 1024 px of window the sidebar leaves the row and waits off
 * the left edge; the Menu button at the start of the top bar slides it over the page, and a link, the backdrop, Escape or
 * the button again puts it away. The page takes the whole width, inside a 16 px gutter. On a wider window nothing changes.
 */
const navOpen = ref(false)

const toggleNav = () => {
    navOpen.value = !navOpen.value
    menuOpen.value = false
}

const closeNav = () => {
    navOpen.value = false
}

let stopClosingNav = null

const signOut = () => router.post('/logout')

const overridePin = () => {
    menuOpen.value = false
    router.get('/account/override-pin')
}

// M32 F9: the person's own account — details, password, two-step verification, where they are signed in.
const openAccount = () => {
    menuOpen.value = false
    router.get('/account')
}

/*
 * M10 §1.4: the way back to the page that proved this account's alert address, so a
 * changed address can be changed. Drawn only for the roles a panic reaches — for
 * anybody else there is nothing an alert would use.
 */
const alertContact = () => {
    menuOpen.value = false
    router.get('/verify-contact')
}

// M12 §4.8: the person's own profile picture, in a dialog over whatever page they are on.
const photoOpen = ref(false)
const openPhoto = () => {
    menuOpen.value = false
    photoOpen.value = true
}

/* Clicking anywhere else closes it. Registered on the document rather than an
 * overlay element so the board's layout gains no extra box. */
const closeOnOutside = (event) => {
    if (!event.target.closest('.side-foot')) {
        menuOpen.value = false
    }
}

const closeOnEscape = (event) => {
    if (event.key === 'Escape') {
        menuOpen.value = false
        navOpen.value = false
    }
}

onMounted(() => {
    document.addEventListener('click', closeOnOutside)
    document.addEventListener('keydown', closeOnEscape)
    // A link chosen in the sidebar on a phone puts the sidebar away as the next page arrives.
    stopClosingNav = router.on('navigate', closeNav)
})

onBeforeUnmount(() => {
    clearTimeout(searchTimer)
    document.removeEventListener('click', closeOnOutside)
    document.removeEventListener('keydown', closeOnEscape)
    stopClosingNav?.()
})
</script>

<template>
    <!--
      A PANIC, OVER WHATEVER PAGE THIS READER IS ON (M10 §1.2). Outside
      `.app-shell` so the board's own fixed layout cannot clip it. The overlay
      is a component with its own authored styles, not a rule of this layout's —
      the no-authored-CSS note above is about the board's own chrome. Before the
      shell, never inside it: the red bar sits in the page above the shell and
      pushes it down (M60 §C4).
    -->
    <PanicOverlay
        v-if="panicWatch"
        variant="console"
        :alert="panic.showing.value"
        :blinking="panic.blinking.value"
        :current="panic.current.value"
        :outstanding="panic.outstanding.value"
        @dismiss="panic.dismiss()"
    />

    <!--
      Every message, top right, each with a close control (M95 §2), as the estate shell draws it. Until 11 October a toast
      on a Gemini page rendered nowhere (the Security session's report); since 12 October the server's flash is said here
      (above), and the pages that drew it as a banner of their own no longer do.
    -->
    <ToastStack />

    <div class="app-shell">
        <div id="gemini-nav" class="sidebar sidebar--gemini" :class="{ 'sidebar--open': navOpen }">
            <div class="side-logo">
                <BrandMark colourway="console" />
                <div>
                    <div class="lt">GeminiSecure</div>
                    <div class="ls">GEMINI CONSOLE</div>
                </div>
            </div>

            <!--
              The links, in a box of their own: the sidebar holds still while the
              page scrolls, the logo stays at the top and the account at the
              bottom, and only this box scrolls, when the pointer is over it and
              there are more links than fit (app.css).
            -->
            <nav class="side-scroll" aria-label="Gemini console">
                <Link
                    v-for="module in ungrouped"
                    :key="module.key"
                    :href="module.href"
                    class="nav-item"
                    :class="{ active: module.active }"
                >
                    <ModuleIcon :module="module.key" />
                    <span>{{ module.label }}</span>
                </Link>

                <template v-for="section in sections" :key="section.name">
                    <div class="nav-section">{{ section.name }}</div>
                    <Link
                        v-for="module in section.modules"
                        :key="module.key"
                        :href="module.href"
                        class="nav-item"
                        :class="{ active: module.active }"
                    >
                        <ModuleIcon :module="module.key" />
                        <span>{{ module.label }}</span>
                    </Link>
                </template>
            </nav>

            <!--
              The board draws this as a <div>. It is a <button> because it is
              the only route to signing out, and it is on every screen.
            -->
            <button
                type="button"
                class="side-foot"
                :aria-expanded="menuOpen"
                aria-haspopup="menu"
                @click="menuOpen = !menuOpen"
            >
                <ConsoleAvatar box="sf-avatar" :user="user" />
                <div>
                    <div class="sf-name">{{ user?.name }}</div>
                    <div class="sf-role">{{ user?.role_label }}</div>
                </div>

                <div v-if="menuOpen" class="sf-menu" role="menu">
                    <button type="button" role="menuitem" @click.stop="openAccount">My account</button>
                    <!-- M4 §3.4: a supervisor's own clock-in override PIN. Drawn only for somebody who has one to set. -->
                    <button v-if="user?.is_supervisor" type="button" role="menuitem" @click.stop="overridePin">
                        Clock-in override PIN
                    </button>
                    <button v-if="user?.receives_alerts" type="button" role="menuitem" @click.stop="alertContact">
                        Alert contact
                    </button>
                    <button type="button" role="menuitem" @click.stop="openPhoto">Profile picture</button>
                    <button type="button" role="menuitem" @click.stop="signOut">Sign out</button>
                </div>
            </button>
        </div>

        <!-- On a phone, the page behind the open sidebar: a tap on it puts the sidebar away. -->
        <div v-if="navOpen" class="nav-backdrop" aria-hidden="true" @click="closeNav"></div>

        <div class="main-col">
            <div class="topbar">
                <!--
                  M95 §3: on a nested page, the way back to its list, in words, before the title (the shared BackLink, as
                  the estate shell draws it). The lead slot stays for a page that draws something else there.
                -->
                <BackLink v-if="back" :href="back.href" :label="back.label" />

                <!--
                  On a phone or a narrow window only: the way to the sidebar, which waits off the screen there. After the
                  back link in the markup, as in the estate shell; the style draws it first, at the left edge.
                -->
                <button
                    type="button"
                    class="nav-toggle"
                    aria-controls="gemini-nav"
                    :aria-expanded="navOpen"
                    :aria-label="navOpen ? 'Close the menu' : 'Open the menu'"
                    title="Menu"
                    @click.stop="toggleNav"
                >
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                    </svg>
                </button>

                <slot name="lead" />

                <h1>{{ title }}</h1>

                <!--
                  Immediately after the title, and only on the screens that pass
                  one. Roughly forty screens show data the Guard App and the
                  Resident App generate, and until those apps exist the simulator
                  produces it — so a reviewer looking at the live map cannot tell
                  from the screen alone whether the alert in front of them came
                  from a handset at a gate. The badge says which, beside the
                  heading it qualifies rather than buried in the row it
                  describes. See App\Support\SourceBadge.
                -->
                <slot name="byline" />

                <!-- Whether this screen is live, said plainly (M11 Part C, DESIGN §6.4): nothing while it is. -->
                <LiveStatus />

                <!--
                  SITE TIME OR MY TIME (M19A §7). Site time — every row on its own estate's clock, "9:38 AM JA" — is the
                  default; My time puts the whole console on this viewer's clock. Remembered on the user.
                -->
                <div v-if="timeView" class="time-view" role="group" aria-label="Whose clock this console shows">
                    <button type="button" :class="{ on: timeView.view === 'site' }" :aria-pressed="timeView.view === 'site'" @click="chooseTimeView('site')">Site time</button>
                    <button type="button" :class="{ on: timeView.view === 'mine' }" :aria-pressed="timeView.view === 'mine'" :title="myZoneTitle" @click="chooseTimeView('mine')">My time</button>
                </div>

                <div v-if="searchRoute || searchDisabledReason" class="top-search">
                    <svg viewBox="0 0 24 24" fill="none">
                        <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.8" />
                        <path d="M21 21l-4.3-4.3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                    </svg>
                    <input
                        v-model="query"
                        type="search"
                        :placeholder="searchPlaceholder"
                        :disabled="!searchRoute"
                        :title="searchRoute ? undefined : searchDisabledReason"
                        @input="runSearch"
                    />
                </div>

                <div v-if="$slots.actions" class="top-right">
                    <slot name="actions" />
                </div>
            </div>

            <div class="content">
                <slot />
            </div>
        </div>
    </div>

    <ProfilePhotoDialog :open="photoOpen" :user="user" @close="photoOpen = false" />
</template>

<style scoped>
/*
 * The only authored CSS in this component, and only where a board <div> had to
 * become a real control.
 *
 * The board draws the search as a <span> of placeholder text and the sidebar
 * footer as a <div>. Both are real controls here, and a browser's default
 * styling for <input> and <button> — border, background, font, padding, width
 * — would otherwise show through and change the pixels. These rules take those
 * defaults back off so the board's own .top-search and .side-foot rules are
 * what is seen. Nothing here introduces a colour, size or spacing the board
 * does not already declare.
 */
.top-search input {
    flex: 1;
    min-width: 0;
    border: 0;
    outline: 0;
    background: transparent;
    font: inherit;
    color: inherit;
    padding: 0;
}

.top-search input::placeholder {
    color: inherit;
    opacity: 1;
}

/* Chrome paints a clear button and a magnifier on type="search". The board
 * draws neither, and its own icon is already in the box. */
.top-search input::-webkit-search-decoration,
.top-search input::-webkit-search-cancel-button {
    -webkit-appearance: none;
    appearance: none;
}

/* Inert, and it says so on hover. No opacity change: the board draws the field
 * at one weight, and dimming it would be a pixel the design does not have. */
.top-search input[disabled] {
    cursor: not-allowed;
}

/* A <button> shrinks to its content and centres its text; the board's
 * .side-foot is a full-width row. margin-top:auto is the board's own rule and
 * is not restated here — only what the UA adds is removed. */
.side-foot {
    width: 100%;
    border: 0;
    background: transparent;
    font: inherit;
    color: inherit;
    text-align: left;
    cursor: pointer;
    position: relative;
}

.sf-menu {
    position: absolute;
    bottom: calc(100% + 8px);
    left: 0;
    right: 0;
    z-index: 20;
    background: var(--white);
    border-radius: 10px;
    box-shadow: 0 12px 28px rgba(11, 31, 51, 0.28);
    padding: 6px;
}

.sf-menu button {
    display: block;
    width: 100%;
    border: 0;
    background: transparent;
    font: inherit;
    color: var(--navy-900);
    text-align: left;
    padding: 8px 10px;
    border-radius: 7px;
    cursor: pointer;
}

.sf-menu button:hover {
    background: var(--navy-100);
}

/*
 * THE BAR WRAPS WHEN IT IS FULL (Hassam, from the Security session, 11 October 2026). The board's top bar is one row,
 * 64px tall, that cannot wrap: on the live map the title, its byline, the live line, the clock switch, the search and
 * the tabs did not fit, so the live line was crushed into a column over the content and the tabs ran off the edge. A
 * full bar now grows a second row instead, never shorter than the board's 64px, and its tabs wrap among themselves at a
 * narrow width; a bar that fits is drawn as the board draws it. Named through its parent, so it outranks the board's
 * own `body.wf-… .topbar`.
 */
.main-col > .topbar {
    height: auto;
    min-height: 64px;
    flex-wrap: wrap;
    row-gap: 8px;
    padding-top: 12px;
    padding-bottom: 12px;
}

.main-col > .topbar > .top-right {
    flex-wrap: wrap;
    justify-content: flex-end;
    row-gap: 8px;
}

/*
 * THE SIDEBAR ON A PHONE OR A NARROW WINDOW, the estate shell's rules (369e87ef) on this shell's classes. Above 1023 px
 * nothing here applies: the Menu button and the backdrop are not drawn and the sidebar is the board's. Below it the
 * sidebar is a drawer over the page, the top bar wraps its controls instead of pushing the page sideways, and the page
 * has a 16 px gutter. In the shell's own scoped style, because resources/css is pinned with the classic pages; each
 * selector names the shell's own classes so it outranks the board's sheet without !important.
 */
.nav-toggle,
.nav-backdrop {
    display: none;
}

@media (max-width: 1023px) {
    /* The modifier class outranks app.css's pinned sidebar, `body[class*='wf-'] .app-shell > .sidebar` (0,3,1). */
    .app-shell > .sidebar.sidebar--gemini {
        position: fixed;
        top: var(--panic-bar-h, 0px);
        bottom: 0;
        left: 0;
        height: auto;
        align-self: stretch;
        width: min(280px, 85vw);
        z-index: 60;
        transform: translateX(-100%);
        visibility: hidden;
        transition: transform 0.2s ease, visibility 0.2s;
    }

    .app-shell > .sidebar.sidebar--gemini.sidebar--open {
        transform: none;
        visibility: visible;
        box-shadow: 0 24px 60px rgba(11, 31, 51, 0.35);
    }

    .app-shell > .nav-backdrop {
        display: block;
        position: fixed;
        top: var(--panic-bar-h, 0px);
        right: 0;
        bottom: 0;
        left: 0;
        z-index: 55;
        background: rgba(11, 31, 51, 0.45);
    }

    .topbar > .nav-toggle {
        display: flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 auto;
        order: -1;
        width: 38px;
        height: 38px;
        padding: 0;
        border: 0;
        border-radius: 10px;
        background: var(--navy-100);
        color: var(--navy-700);
        cursor: pointer;
    }

    .topbar > .nav-toggle svg {
        width: 18px;
        height: 18px;
        display: block;
    }

    .topbar > .nav-toggle:focus-visible {
        outline: 2px solid var(--amber-500);
        outline-offset: 2px;
    }

    .main-col > .topbar {
        min-height: 56px;
        padding: 9px 16px;
        gap: 8px 10px;
    }

    .main-col > .topbar > .top-right {
        gap: 8px;
        max-width: 100%;
    }

    .topbar > .top-search {
        flex: 1 1 100%;
        max-width: none;
        order: 10;
    }

    .main-col > .content {
        padding: 16px;
    }

    /*
     * EVERY PAGE INSIDE THE WINDOW, the estate shell's phone rules (Hassam, 12 Oct; Accounting measured Gemini invoices
     * at 477 px, plans 440, MRR 565 and revenue 460 in a 390 px window). The main column may shrink below its widest
     * child; a tab row scrolls inside itself; chips and action rows wrap; summary grids fall to two columns; a wide
     * table scrolls in its own frame instead of widening the page.
     */
    .app-shell > .main-col {
        min-width: 0;
        max-width: 100vw;
    }

    .main-col > .content {
        min-width: 0;
    }

    .main-col > .content :deep(.subnav) {
        width: auto;
        max-width: 100%;
        overflow-x: auto;
        flex-wrap: nowrap;
        scrollbar-width: none;
    }

    .main-col > .content :deep(.subnav-item) {
        flex: 0 0 auto;
        white-space: nowrap;
    }

    .main-col > .content :deep(.filter-row),
    .main-col > .content :deep(.row-actions),
    .main-col > .content :deep(.stat-row) {
        flex-wrap: wrap;
    }

    .main-col > .content :deep(.kpi-row),
    .main-col > .content :deep(.sum-row) {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .main-col > .content :deep(.report-grid),
    .main-col > .content :deep(.settings-layout) {
        grid-template-columns: minmax(0, 1fr);
    }

    .main-col > .content :deep(.data-table) {
        display: block;
        max-width: 100%;
        overflow-x: auto;
    }
}

/*
 * THE PAGE'S HEADER STAYS IN VIEW, as on the estate console (Hassam, 12 Oct, batch 1 item 4): pinned below the red bar.
 */
.main-col > .topbar {
    position: sticky;
    top: var(--panic-bar-h, 0px);
    z-index: 4;
}

/* M19A §7 — the site-time / my-time switch. Authored: no board draws it. */
.time-view {
    display: inline-flex;
    margin-left: 10px;
    border: 1.5px solid var(--navy-200);
    border-radius: 9px;
    overflow: hidden;
    flex: 0 0 auto;
}

.time-view button {
    -webkit-appearance: none;
    appearance: none;
    border: 0;
    background: var(--white);
    color: var(--navy-900);
    font: inherit;
    font-size: 11.5px;
    font-weight: 700;
    padding: 5px 10px;
    cursor: pointer;
}

.time-view button.on {
    background: var(--navy-900);
    color: var(--white);
}
</style>
