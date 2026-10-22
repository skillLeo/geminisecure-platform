<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import BrandMark from '../Components/BrandMark.vue'
import EstateIcon from '../Components/EstateIcon.vue'
import LiveStatus from '../Components/LiveStatus.vue'
import { startLive } from '../live/index.js'
import PanicOverlay from '../Components/PanicOverlay.vue'
import DocumentHandOver from '../Components/DocumentHandOver.vue'
import ConsoleAvatar from '../Components/ConsoleAvatar.vue'
import ProfilePhotoDialog from '../Components/ProfilePhotoDialog.vue'
import BackLink from '../Components/BackLink.vue'
import ToastStack from '../Components/ToastStack.vue'
import { ESTATE_POLL_MS, useEstatePanic } from '../composables/useEstatePanic'
import { toast } from '../lib/toasts.js'

/**
 * The Estate Console shell.
 *
 * Same class names as GeminiConsole — the wireframes draw both consoles with
 * identical chrome — with the estate colourway on the brand mark and the
 * estate's own name where Gemini's wordmark sits.
 *
 * NAVIGATION COMES FROM THE VIEWER'S PERMISSIONS, resolved server-side by
 * `EstateNavigation`. A role that holds no module behind an item does not see
 * the item: the Build Spec is explicit that "a role without a module permission
 * does not see that module at all", which is why a Property Manager opening
 * this console finds no Dues & ledger and no Accounting. That is the separation
 * working, not a fault to explain.
 *
 * Every item that appears is a link. Through the build an item whose module had
 * no screens yet was drawn visibly inert with a reason rather than as a link to
 * a route that answers 404; Reports was the last of the ten, so the server
 * cannot produce one any more (D-070).
 */
const props = defineProps({
    title: { type: String, required: true },
    estateName: { type: String, required: true },
    /** Search box in the topbar — drawn on the index screens, absent on details. */
    searchPlaceholder: { type: String, default: null },

    /*
     * WHERE A TYPED QUERY GOES, AND THE FIELD IS ONLY REAL WHEN A SCREEN SAYS SO.
     *
     * This box was hardcoded `disabled` on every estate screen, with a tooltip
     * saying a search needed an index first. That had stopped being true:
     * `Residents::listBoard()` takes a `search` argument and matches household
     * name, resident full name, lot reference and phase — the exact three the
     * placeholder promises. So the register was capped at LIST_LIMIT (50) of an
     * estate's 441 households with no way to reach the other 391, and the only
     * control that could have narrowed them was switched off. Somebody looking
     * for a named resident had nothing to use but the browser's own find, which
     * searches the fifty rows already on screen and reports nothing found.
     *
     * A screen passes `searchRoute` when its server-side filter exists. Those
     * that have none keep the honest disabled field rather than gaining a box
     * that would return nothing.
     */
    searchRoute: { type: String, default: null },

    /** What the server currently has filtered on, so the field reads back its own state. */
    searchValue: { type: String, default: '' },

    /**
     * The other filters this screen is currently under, carried with the query.
     *
     * Without it, typing a name would silently drop the phase chip the operator
     * had already chosen and widen the list they had just narrowed.
     */
    searchParams: { type: Object, default: () => ({}) },
    /** Which sidebar item this screen sits under. */
    active: { type: String, default: '' },

    /**
     * Whether the topbar carries the signed-in profile chip.
     *
     * It varies board to board and is not decoration: the dashboard draws it,
     * the accounting and detail screens do not, and rendering it everywhere
     * pushes the topbar's own controls out of position on every screen that
     * omits it.
     */
    profile: { type: Boolean, default: false },

    /**
     * THE WAY BACK, ON A NESTED PAGE (M95 §3): `{ href, label }`, "Back to bills", drawn top left before the title. A
     * page the sidebar opens passes none: it is the top of its own section.
     */
    back: { type: Object, default: null },
})

const page = usePage()

/*
 * EVERY MESSAGE A PAGE IS HANDED IS A TOAST, TOP RIGHT (M95 §2). The server's flash, a success, a refusal or what a
 * door said on the way, used to be a banner each page drew into itself, so a page grew by a line nobody saw, and the
 * remittance advice said "Preparing" twice. The shell says each one once, in the stack; no page draws its own.
 * A document being prepared is said by `DocumentHandOver`, in place, so its flash is not said a second time.
 */
watch(
    () => page.props.flash,
    (flash) => {
        if (!flash) {
            return
        }

        if (flash.success && !flash.document) {
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

const user = computed(() => page.props.auth?.user ?? null)
const nav = computed(() => page.props.estateNav ?? [])

// The avatar — the person's picture, or their initials — is ConsoleAvatar's (M12 §4.8).

/** The ungrouped items, then each section in the order the boards draw them. */
const ungrouped = computed(() => nav.value.filter((item) => !item.section))

const sections = computed(() => {
    const groups = new Map()

    for (const item of nav.value.filter((i) => i.section)) {
        if (!groups.has(item.section)) {
            groups.set(item.section, [])
        }
        groups.get(item.section).push(item)
    }

    return [...groups].map(([name, items]) => ({ name, items }))
})

/* --- a panic, wherever the reader is (M10 §1) -------------------------- */

/*
 * MOUNTED ON THE SHELL, WHICH IS THE WHOLE POINT. This component wraps all
 * fifty-two estate screens, so subscribing here is what makes an emergency reach
 * a committee member reading the arrears register. A per-screen subscription
 * would only alert the people already looking at the right page — the one group
 * that does not need telling.
 *
 * `panicWatch` is null for anybody without `estate.security.view`, and the
 * composable is then handed no estate and never opens a socket. Otherwise it
 * carries what nobody in the control room has acknowledged yet, so the bar is
 * on every page until the server says otherwise (B039, B103) — the composable
 * keeps the visit's alerts itself, because this shell is built afresh by each
 * page.
 */
const panicWatch = computed(() => page.props.panicWatch ?? null)

/*
 * Where this console is rooted, read off the page's own URL — production gives
 * each estate its own hostname and local puts the estate key in the path, so
 * cutting the URL that served this page is right in both.
 *
 * The estate's own home is `/estate/{key}` with nothing after it, and cutting at
 * the next "/" found none there — so on the dashboard the root came out empty,
 * and every link and request built on it (the pop-up's "Open Security", its
 * poll) went to the central host and 404'd, locally.
 */
// "/estate/{key}" up to the next "/", "?" or "#", or the end: the estate's own
// home page, "/estate/phoenixpark", has nothing after the key at all.
const consoleRoot = computed(() => (page.url ?? '').match(/^\/estate\/[^/?#]+/)?.[0] ?? '')

// After the root, which the pop-up's poll is asked under.
const panic = useEstatePanic(panicWatch.value, {
    owner: user.value?.id ?? null,
    root: consoleRoot.value,
})

/** How often the composable asks while the channel is down — said in the notice, never guessed. */
const pollSeconds = ESTATE_POLL_MS / 1000

/* --- search ----------------------------------------------------------- */

/*
 * Server-side, and debounced — the same shape the Gemini console uses.
 *
 * `preserveState` keeps the field focused and the caret where it was, which a
 * full visit would throw away on every keystroke. `replace` keeps the browser
 * history from filling with one entry per character.
 */
const query = ref(props.searchValue ?? '')

let searchTimer = null

/** What this field last asked the server to search for. */
let sent = props.searchValue ?? ''

const runSearch = () => {
    if (!props.searchRoute) {
        return
    }

    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => {
        searchTimer = null
        sent = query.value

        router.get(
            props.searchRoute,
            { ...props.searchParams, q: query.value || undefined },
            { preserveState: true, preserveScroll: true, replace: true }
        )
    }, 250)
}

/*
 * The server is the authority on what is filtered. Following a phase chip or the
 * back button changes it without a keystroke, and the field has to agree.
 *
 * BUT NOT WITH ITS ANSWER TO WHAT THIS FIELD ASKED (B109). Laravel trims every
 * query (TrimStrings), so the answer to "Mary " comes back as "Mary" — and the
 * field was set to it, losing the space and every letter typed while the answer
 * was on its way, after which the pending search sent the clobbered value: a
 * reader typing "Mary Brown" got "MaryBrown" and "no household matches". So:
 *
 *   - while a keystroke's search is still pending, the field is the reader's,
 *     and that search will bring the server into line;
 *   - an answer that is what this field asked for, trimmed, is already in the
 *     field — with whatever has been typed since — and is left there;
 *   - anything else is the filter changed from elsewhere, and the field follows.
 */
watch(
    () => props.searchValue,
    (value) => {
        const incoming = value ?? ''

        if (searchTimer !== null || incoming.trim() === sent.trim()) {
            return
        }

        sent = incoming
        query.value = incoming
    }
)

/* --- the account menu ------------------------------------------------- */

/*
 * SIGNING OUT, WHICH THIS CONSOLE HAD NO WAY TO DO.
 *
 * The sidebar footer and the topbar chip both drew the signed-in name as a
 * plain <div>, exactly as the boards draw them — so a committee account that
 * reached this console was locked inside it. There was no sign-out on any
 * estate screen, and `/logout` is a POST, so no address could be typed to
 * escape either: the only way to become a different user was to clear the
 * cookie by hand. That made every OTHER committee role untestable, because
 * reviewing the second one meant first getting out of the first.
 *
 * Both chips are real controls now, each opening the same menu, because the
 * boards put the name in two places and a person reaching for the one in the
 * topbar is not wrong. `logout` is registered above the Gemini group in
 * routes/web.php on `auth` alone, so it is reachable from an estate hostname.
 */
const menuOpen = ref(false)
const topMenuOpen = ref(false)

/*
 * THE SIDEBAR ON A PHONE (Hassam, 12 Oct: it "keeps its full sidebar on a phone, leaving every page about 150 px
 * wide"). Below 1024 px of window the sidebar leaves the row and waits off the left edge of the screen; the Menu button
 * at the start of the top bar slides it over the page, and a link, the backdrop, Escape or the button again puts it
 * away. The page takes the whole width, inside a 16 px gutter. On a wider window nothing changes.
 */
const navOpen = ref(false)

const toggleNav = () => {
    navOpen.value = !navOpen.value
    menuOpen.value = false
    topMenuOpen.value = false
}

const closeNav = () => {
    navOpen.value = false
}

let stopClosingNav = null

const signOut = () => router.post('/logout')

/*
 * Only where the viewer may actually land. `/settings/profile` sits behind
 * `can:estate.settings.view` (routes/tenant.php), so offering it to a role
 * without that permission would be a menu item that answers 403 — the same
 * dead end the sidebar itself is careful never to draw. The nav already says
 * who holds it, so this asks that rather than re-deriving the permission.
 */
const canReachProfile = computed(() => nav.value.some((item) => item.key === 'settings'))

const openProfile = () => {
    menuOpen.value = false
    topMenuOpen.value = false
    // From this console's root, like "Alert contact" below: a bare root path
    // is a 404 in the local /estate/{tenant} shape.
    router.get(`${consoleRoot.value}/settings/profile`)
}

/*
 * M10 §1.4: the way back to the page that proved this account's alert address, on
 * this estate's own door, so a changed address can be changed. Every estate-console
 * account meets the contact gate, so every one of them is offered it.
 */
const openAlertContact = () => {
    menuOpen.value = false
    topMenuOpen.value = false
    router.get(`${consoleRoot.value}/verify-contact`)
}

// M32 F9: the person's own account — details, password, two-step verification, where they are signed in.
const openAccount = () => {
    menuOpen.value = false
    topMenuOpen.value = false
    router.get(`${consoleRoot.value}/account`)
}

// M12 §4.8: the person's own profile picture, in a dialog over whatever page they are on.
const photoOpen = ref(false)
const openPhoto = () => {
    menuOpen.value = false
    topMenuOpen.value = false
    photoOpen.value = true
}

/* Clicking anywhere else closes them. Registered on the document rather than an
 * overlay element so the board's layout gains no extra box. */
const closeOnOutside = (event) => {
    if (!event.target.closest('.side-foot')) {
        menuOpen.value = false
    }

    if (!event.target.closest('.top-profile')) {
        topMenuOpen.value = false
    }
}

const closeOnEscape = (event) => {
    if (event.key === 'Escape') {
        menuOpen.value = false
        topMenuOpen.value = false
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

/*
 * EVERY ITEM IN THIS SIDEBAR IS A LINK, AND THERE IS NO LONGER AN INERT ONE.
 *
 * Through the build an item whose module had no screens yet arrived with a null
 * href and was drawn as a disabled button saying so, because a role checking
 * what it will reach is better served by a greyed row than by an absence.
 * Reports was the tenth and last, so the server cannot produce one any more and
 * the branch that drew it is gone rather than left as markup nothing reaches
 * (D-070). `EstateNavigation` types the href non-null and its test asserts it.
 */
</script>

<template>
    <!--
      A PANIC, OVER WHATEVER PAGE THIS READER IS ON (M10 §1). Outside `.app-shell`
      so the board's own fixed layout cannot clip it, and drawn only for the roles
      that hold `estate.security.view` — which the matrix already makes exactly
      the five the client named. Before the shell, never inside it: the red bar
      sits in the page above the shell and pushes it down (M60 §C4).
    -->
    <PanicOverlay
        v-if="panicWatch"
        :alert="panic.showing.value"
        :blinking="panic.blinking.value"
        :current="panic.current.value"
        :outstanding="panic.outstanding.value"
        :root="consoleRoot"
        @dismiss="panic.dismiss()"
        @clear="panic.clear()"
    />

    <!-- A document asked for on any screen is waited for and handed over (M56 §5). -->
    <DocumentHandOver />

    <!-- Every message, top right, each with a close control (M95 §2). Never the red emergency bar above. -->
    <ToastStack />

    <div class="app-shell">
        <div id="estate-nav" class="sidebar sidebar--estate" :class="{ 'sidebar--open': navOpen }">
            <div class="side-logo">
                <BrandMark colourway="estate" />
                <div>
                    <div class="lt">GeminiSecure</div>
                    <div class="ls">ESTATE CONSOLE</div>
                </div>
            </div>

            <!--
              The links, in a box of their own: the sidebar holds still while the
              page scrolls, the logo stays at the top and the account at the
              bottom, and only this box scrolls, when the pointer is over it and
              there are more links than fit (app.css).
            -->
            <nav class="side-scroll" aria-label="Estate console">
                <Link
                    v-for="item in ungrouped"
                    :key="item.key"
                    :href="item.href"
                    class="nav-item"
                    :class="{ active: item.key === props.active }"
                >
                    <EstateIcon :name="item.icon" />
                    <span>{{ item.label }}</span>
                </Link>

                <template v-for="section in sections" :key="section.name">
                    <div class="nav-section">{{ section.name }}</div>

                    <Link
                        v-for="item in section.items"
                        :key="item.key"
                        :href="item.href"
                        class="nav-item"
                        :class="{ active: item.key === props.active }"
                    >
                        <EstateIcon :name="item.icon" />
                        <span>{{ item.label }}</span>
                    </Link>
                </template>
            </nav>

            <!--
              The board draws this as a <div>. It is a <button> because it
              carries the only route to signing out, and it is on every screen
              this console has — unlike the topbar chip below, which the detail
              boards omit.
            -->
            <button
                type="button"
                class="side-foot"
                :aria-expanded="menuOpen"
                aria-haspopup="menu"
                @click="menuOpen = !menuOpen; topMenuOpen = false"
            >
                <ConsoleAvatar box="sf-avatar" :user="user" :root="consoleRoot" />
                <div>
                    <div class="sf-name">{{ user?.name }}</div>
                    <div class="sf-role">{{ user?.role_label }}</div>
                </div>

                <div v-if="menuOpen" class="sf-menu" role="menu">
                    <button type="button" role="menuitem" @click.stop="openAccount">My account</button>
                    <button v-if="canReachProfile" type="button" role="menuitem" @click.stop="openProfile">
                        My profile
                    </button>
                    <button type="button" role="menuitem" @click.stop="openPhoto">Profile picture</button>
                    <button v-if="user?.receives_alerts" type="button" role="menuitem" @click.stop="openAlertContact">
                        Alert contact
                    </button>
                    <button type="button" role="menuitem" @click.stop="signOut">Sign out</button>
                </div>
            </button>
        </div>

        <!-- On a phone, the page behind the open sidebar: a tap on it puts the sidebar away. -->
        <div v-if="navOpen" class="nav-backdrop" aria-hidden="true" @click="closeNav"></div>

        <div class="main-col">
            <!--
              A DEAD ALERT CHANNEL IS SAID, ON EVERY PAGE (B110). A refused
              subscription, a dropped socket or one that never answers leaves this
              console looking exactly like a quiet night while no pop-up can arrive
              live. The composable polls the server instead while the channel is
              down, and this says so — and says so more plainly when that check has
              failed too. Drawn only for the readers the pop-up is for.
            -->
            <div v-if="panicWatch && panic.down.value" class="panic-feed-down" role="status">
                <span v-if="panic.lastCheckFailed.value">
                    Panic alerts are not reaching this screen: the live alert channel is down, and the last check
                    for alerts got no answer.
                </span>
                <span v-else>
                    The live alert channel is down, so a panic reaches this screen from a check every
                    {{ pollSeconds }} seconds rather than the moment it is raised.
                </span>
                <Link :href="`${consoleRoot}/security`">Open Security</Link>
            </div>

            <div class="topbar">
                <!-- M95 §3: on a nested page, the way back to its list, in words, before the title. -->
                <BackLink v-if="back" :href="back.href" :label="back.label" />

                <!--
                  On a phone or a narrow window only: the way to the sidebar, which waits off the screen there. After
                  the back link here, so the back link stays the bar's first thing (console-components pins it); the
                  style draws it first, at the left edge, where it is shown at all.
                -->
                <button
                    type="button"
                    class="nav-toggle"
                    aria-controls="estate-nav"
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

                <h1 :title="title">{{ title }}</h1>

                <!--
                  The Category B badge (13 C3), immediately after the title and
                  only on screens whose records a handset writes — tickets,
                  bookings, claims, ballots. The same slot, in the same place, as
                  the Gemini console's. See docs/CATEGORY_B.md.
                -->
                <slot name="byline" />

                <!-- Whether this screen is live, said plainly (M11 Part C, DESIGN §6.4): nothing while it is. -->
                <LiveStatus />

                <!--
                  The search field. A real form, because a field that takes a
                  query and does nothing with it is worse than one that says it
                  is not ready — and the boards draw it on the index screens
                  only, so it is absent rather than empty on a detail screen.
                -->
                <form v-if="searchPlaceholder" class="top-search" @submit.prevent="runSearch">
                    <svg viewBox="0 0 24 24" fill="none">
                        <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.8" />
                        <path d="M21 21l-4.3-4.3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                    </svg>
                    <input
                        v-model="query"
                        type="search"
                        :placeholder="searchPlaceholder"
                        :disabled="!searchRoute"
                        :title="
                            searchRoute
                                ? undefined
                                : 'This screen has no server-side search yet, so the box would return nothing. Residents searches by name, lot and phase.'
                        "
                        @input="runSearch"
                    />
                </form>

                <div class="top-right">
                    <slot name="actions" />

                    <!--
                      Also a real control, and for the same reason the sidebar
                      footer is: the boards draw the signed-in name here too,
                      and somebody reaching for THIS one to sign out is not
                      wrong. Same menu, opened from the other end of the shell.
                    -->
                    <button
                        v-if="profile"
                        type="button"
                        class="top-profile"
                        :aria-expanded="topMenuOpen"
                        aria-haspopup="menu"
                        @click="topMenuOpen = !topMenuOpen; menuOpen = false"
                    >
                        <ConsoleAvatar box="tp-avatar" :user="user" :root="consoleRoot" />
                        <div>
                            <div class="tp-name">{{ user?.name }}</div>
                            <div class="tp-role">{{ user?.role_label }}</div>
                        </div>

                        <div v-if="topMenuOpen" class="tp-menu" role="menu">
                            <button type="button" role="menuitem" @click.stop="openAccount">My account</button>
                            <button v-if="canReachProfile" type="button" role="menuitem" @click.stop="openProfile">
                                My profile
                            </button>
                            <button type="button" role="menuitem" @click.stop="openPhoto">Profile picture</button>
                            <button
                                v-if="user?.receives_alerts"
                                type="button"
                                role="menuitem"
                                @click.stop="openAlertContact"
                            >
                                Alert contact
                            </button>
                            <button type="button" role="menuitem" @click.stop="signOut">Sign out</button>
                        </div>
                    </button>
                </div>
            </div>

            <div class="content">
                <slot />
            </div>
        </div>
    </div>

    <ProfilePhotoDialog :open="photoOpen" :user="user" :root="consoleRoot" @close="photoOpen = false" />
</template>

<style scoped>
/*
 * The Community Admin sidebar ends its gradient in #0A2D52 on all 40 approved
 * screens, where the Gemini sidebar ends in --purple-900. Recorded as an open
 * question in DESIGN_SYSTEM_FINDINGS; reproduced faithfully here rather than
 * quietly normalised to the Gemini value.
 */
.sidebar--estate {
    background: linear-gradient(180deg, var(--navy-800), #0a2d52);
}

/*
 * Default-removal only. The boards draw every nav item as a <div>; here they
 * are anchors, which arrive with an underline. The board's .nav-item supplies
 * everything else visible.
 */
a.nav-item {
    text-decoration: none;
}

/*
 * DR-126 (M83 item 6): a long title, a work order's whole name, ends in an ellipsis instead of pushing the page wider
 * than the window at 125% zoom; its full words are its tooltip. Here, in the shell's own scoped style, because
 * resources/css is pinned with the classic Gemini pages (M81 §1.2.5).
 */
.topbar h1 {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* The board draws the search as a <div> with a span inside. */
form.top-search {
    margin: 0;
}

/*
 * THE BOX ITSELF, WHERE THE BOARD DRAWS NONE (M32 F5). Boards 01 and 02 draw the search box and style it. The screens
 * that gained one with F5 — Maintenance, Vendors, Bills, Notices, Meetings, Claims — wear boards that never drew a
 * search, so it arrived unstyled, its icon as wide as the bar. These are those boards' own rules (board 02's width), at
 * no specificity (`:where`), so a board that draws the box keeps its own exactly.
 */
:where(form.top-search) {
    flex: 1;
    max-width: 340px;
    height: 38px;
    background: var(--navy-100);
    border-radius: 10px;
    display: flex;
    align-items: center;
    padding: 0 13px;
    gap: 9px;
}

:where(.top-search svg) {
    width: 15px;
    height: 15px;
    color: var(--slate-500);
    display: block;
    flex: 0 0 auto;
}

.top-search input {
    border: 0;
    background: none;
    font: inherit;
    color: inherit;
    outline: 0;
    width: 100%;
    padding: 0;
}

.top-search input:disabled {
    cursor: not-allowed;
}

.top-search input::placeholder {
    color: var(--slate-500);
    opacity: 1;
}

/*
 * The two account chips, which the boards draw as <div> and which are now real
 * buttons. A <button> shrinks to its content, centres its text and brings its
 * own border, background and font; these rules take only those UA defaults back
 * off so the board's own .side-foot and .top-profile rules are what is seen.
 * Nothing here introduces a colour, size or spacing the design does not declare
 * — except the two menus, which the boards have no drawing of because until now
 * there was nothing to open.
 */
.side-foot,
.top-profile {
    border: 0;
    background: transparent;
    font: inherit;
    color: inherit;
    text-align: left;
    cursor: pointer;
    position: relative;
}

/* margin-top:auto on .side-foot is the board's own rule and is not restated. */
.side-foot {
    width: 100%;
}

.sf-menu,
.tp-menu {
    position: absolute;
    z-index: 20;
    background: var(--white);
    border-radius: 10px;
    box-shadow: 0 12px 28px rgba(11, 31, 51, 0.28);
    padding: 6px;
}

/* Upwards out of the sidebar footer: there is nothing below it. */
.sf-menu {
    bottom: calc(100% + 8px);
    left: 0;
    right: 0;
}

/* Downwards out of the topbar, and right-aligned — the chip sits at the end of
 * the row, so a menu growing rightwards would leave the viewport. */
.tp-menu {
    top: calc(100% + 8px);
    right: 0;
    min-width: 100%;
    white-space: nowrap;
}

.sf-menu button,
.tp-menu button {
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

.sf-menu button:hover,
.tp-menu button:hover {
    background: var(--navy-100);
}

/*
 * The dead-channel notice (B110). Authored — no board draws it, because the
 * boards assume a live channel. The amber the dispatch screens already use for
 * "the browser is refusing" and "fallback", so it reads as a warning and not
 * as an emergency.
 */
.panic-feed-down {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 12px;
    margin: 0 0 12px;
    padding: 9px 14px;
    border: 1px solid var(--amber-500);
    border-radius: 10px;
    background: var(--amber-100);
    color: var(--navy-900);
    font-size: 12.5px;
    font-weight: 600;
    line-height: 1.45;
}

.panic-feed-down a {
    color: var(--navy-900);
    font-weight: 700;
}

/*
 * THE SIDEBAR ON A PHONE OR A NARROW WINDOW (Hassam, 12 Oct). Above 1023 px nothing here applies: the Menu button and
 * the backdrop are not drawn and the sidebar is the board's. Below it the sidebar is a drawer over the page, the top
 * bar wraps its controls instead of pushing the page sideways, and the page has a 16 px gutter. Here, in the shell's
 * own scoped style, because resources/css is pinned with the classic Gemini pages (M81 §1.2.5); each selector names the
 * shell's own classes so it outranks the board's sheet and app.css's pinned sidebar without !important.
 */
.nav-toggle,
.nav-backdrop {
    display: none;
}

/*
 * THE PAGE'S HEADER STAYS IN VIEW (Hassam, 12 Oct, batch 1 item 4: on a long form the Save button scrolled away and the
 * page had to be scrolled back up to reach it). The top bar, with its title, search and the page's actions, is pinned
 * to the top of the window below the red emergency bar, as the sidebar already is. Every board draws it white with a
 * rule under it, so the page passes cleanly beneath; below the sidebar (5), the emergency bar (900) and any dialog.
 */
.main-col > .topbar {
    position: sticky;
    top: var(--panic-bar-h, 0px);
    z-index: 4;
}

@media (max-width: 1023px) {
    .app-shell > .sidebar.sidebar--estate {
        position: fixed;
        top: var(--panic-bar-h, 0px);
        bottom: 0;
        left: 0;
        height: auto;
        /* app.css pins the desktop sidebar with align-self: flex-start, which a fixed box also obeys (it shrank the
           drawer to its own contents, 161 px tall, seen on the dev server): stretched from top to bottom instead. */
        align-self: stretch;
        width: min(280px, 85vw);
        z-index: 60;
        transform: translateX(-100%);
        visibility: hidden;
        transition: transform 0.2s ease, visibility 0.2s;
    }

    .app-shell > .sidebar.sidebar--estate.sidebar--open {
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
        height: auto;
        min-height: 56px;
        padding: 9px 16px;
        gap: 8px 10px;
        flex-wrap: wrap;
    }

    /*
     * A long title wraps instead of running off the screen (seen at 390 px on the local app, 12 Oct: a work order's name
     * pushed the page to 492 px). Every board sheet sets `.topbar h1 { flex: 0 0 auto }` at `body.wf-… .topbar h1`, which
     * outranks the ellipsis above; this outranks the sheet, on a phone or a narrow window only.
     */
    .main-col > .topbar > h1 {
        flex: 1 1 auto;
        max-width: 100%;
        white-space: normal;
        overflow-wrap: anywhere;
    }

    .topbar > .top-right {
        flex-wrap: wrap;
        gap: 8px;
        max-width: 100%;
    }

    .topbar > form.top-search {
        flex: 1 1 100%;
        max-width: none;
        order: 10;
    }

    .main-col > .content {
        padding: 16px;
    }

    /*
     * EVERY PAGE INSIDE THE WINDOW (Hassam, 12 Oct, at phone width on live: Dues & ledger ran off the right, its title cut
     * on the left). The main column may shrink below its widest child, and the board sheets' rows that never wrap are
     * told to here, once for every estate page: a tab row scrolls sideways inside itself, chips and action rows wrap,
     * the summary card grids fall to two columns, and a wide table scrolls inside its own frame instead of widening the
     * page. The sheets themselves are pinned with the classic Gemini pages (M81 §1.2.5), so the rules live in the shell.
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
    .main-col > .content :deep(.stat-row),
    .main-col > .content :deep(.tmpl-tabs) {
        flex-wrap: wrap;
    }

    .main-col > .content :deep(.kpi-row),
    .main-col > .content :deep(.sum-row),
    .main-col > .content :deep(.age-kpi-row),
    .main-col > .content :deep(.phase-grid) {
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
</style>
