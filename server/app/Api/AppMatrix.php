<?php

declare(strict_types=1);

namespace App\Api;

/**
 * What each mobile app may do — the one source every device ability derives from (13 D1).
 *
 * "Scopes from the matrix. `DeviceEnrolment::GUARD_ABILITIES` is hard-coded and
 * `DeviceAbilities::forGuard` lacks `shifts:clock`. Derive abilities from the
 * role's permissions — one source of truth."
 *
 * WHY THIS IS NOT A ROW IN THE WEB ROLE MATRIX. `App\Enums\Console` records the
 * ruling that the Guard App and the Resident App are not consoles: each has one
 * implicit role (an officer on shift; a resident of a household), no role
 * switching, and no screen that grants or withholds anything. Putting two
 * phantom roles into `roles` would draw them on boards 24 and 45, where nobody
 * can change them. So the apps get their own matrix, in the same notation, and
 * it is the ONLY place an ability is decided:
 *
 *   write   the app may read and change it → abilities `{capability}:read`, `{capability}:write`
 *   read    the app may read it            → ability  `{capability}:read`
 *   none    the app may not reach it       → no ability
 *
 * The token a handset is issued carries `abilitiesFor(app)`; every route in the
 * `Catalogue` names a capability and an access, and its ability middleware is
 * computed from those — never written by hand. `AppMatrixTest` fails if a route
 * asks for an ability no app can hold, if an ability string appears anywhere
 * outside this derivation, or if the two lists disagree.
 */
final class AppMatrix
{
    public const GUARD = 'guard';

    public const RESIDENT = 'resident';

    public const WRITE = 'write';

    public const READ = 'read';

    public const NONE = 'none';

    /**
     * capability => [what it covers, guard, resident]
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const CAPABILITIES = [
        'alerts' => ['Raise, and cancel within the grace period, a panic or duress alert; register this phone to be alerted while the app is closed, and receive live updates while the app is open', self::WRITE, self::WRITE],
        'gate' => ['Verify passes, record entries, exits and overrides, search units, read the gate log', self::WRITE, self::NONE],
        'passes' => ['Issue, share, cancel and read visitor passes; the resident\'s own e-pass', self::NONE, self::WRITE],
        'approvals' => ['Answer a guard\'s walk-up entry request for the resident\'s own unit', self::NONE, self::WRITE],
        'shifts' => ['Read the roster, pre-flight, clock on and off, take breaks, claim open shifts', self::WRITE, self::NONE],
        'orders' => ['Read and acknowledge standing orders for the guard\'s posts', self::WRITE, self::NONE],
        'patrol' => ['Read a site\'s checkpoints and scan them', self::WRITE, self::NONE],
        'alertness' => ['Answer a random alertness check', self::WRITE, self::NONE],
        'presence' => ['Report on-post activity, share the officer\'s position between clock-in and clock-out with their consent, and read the guard\'s own activity summary', self::WRITE, self::NONE],
        'incidents' => ['File incident reports with media, and read the guard\'s own', self::WRITE, self::NONE],
        'requests' => ['Raise leave and equipment requests, and read the guard\'s own', self::WRITE, self::NONE],
        'payslips' => ['Read the guard\'s own payslips — their own wage, and nobody else\'s', self::READ, self::NONE],
        'messages' => ['Read and send messages with dispatch', self::WRITE, self::NONE],
        'sync' => ['Upload a queue captured offline, and pull what changed', self::WRITE, self::NONE],
        'household' => ['The resident\'s own profile, household members, vehicles and emergency contacts', self::NONE, self::WRITE],
        'dues' => ['The resident\'s OWN household\'s invoices, balance, statement and receipts', self::NONE, self::READ],
        'payments' => ['Start a payment and manage AutoPay for the resident\'s own household', self::NONE, self::WRITE],
        'tickets' => ['Report maintenance problems with media, and follow them', self::NONE, self::WRITE],
        'notices' => ['Read notices and mark them read', self::NONE, self::WRITE],
        'meetings' => ['Read meetings and RSVP', self::NONE, self::WRITE],
        'elections' => ['Read elections, check eligibility, cast the household\'s ballot, read results', self::NONE, self::WRITE],
        'bookings' => ['Read amenities, book and cancel', self::NONE, self::WRITE],
    ];

    /**
     * Every ability a handset of this app is issued.
     *
     * @return list<string>
     */
    public static function abilitiesFor(string $app): array
    {
        $column = self::column($app);
        $abilities = [];

        foreach (self::CAPABILITIES as $capability => $cells) {
            $level = $cells[$column];

            if ($level === self::READ || $level === self::WRITE) {
                $abilities[] = self::ability($capability, self::READ);
            }

            if ($level === self::WRITE) {
                $abilities[] = self::ability($capability, self::WRITE);
            }
        }

        return $abilities;
    }

    /**
     * The same abilities WITH EVERY WRITE TAKEN OUT — a token that can look and not touch.
     *
     * Derived here, from the same grid, rather than listed anywhere: the "Explore a demo
     * community" path (M5 §3) hands a reviewer a session in the demonstration estate, and
     * read-only has to be a property of the TOKEN rather than of the screens. A screen that
     * merely hides its buttons is not read-only; a token with no `:write` ability is refused
     * by the route's own middleware, before a controller is reached.
     *
     * @return list<string>
     */
    public static function readOnlyAbilitiesFor(string $app): array
    {
        return array_values(array_filter(
            self::abilitiesFor($app),
            static fn (string $ability): bool => str_ends_with($ability, ':'.self::READ),
        ));
    }

    /** The ability a route needing this access to this capability checks. */
    public static function ability(string $capability, string $access): string
    {
        if (! isset(self::CAPABILITIES[$capability]) || ! in_array($access, [self::READ, self::WRITE], true)) {
            throw new \InvalidArgumentException("No capability [{$capability}:{$access}] in the app matrix.");
        }

        return $capability.':'.$access;
    }

    /** Whether an app's handset may reach a capability at this access. */
    public static function allows(string $app, string $capability, string $access): bool
    {
        return in_array(self::ability($capability, $access), self::abilitiesFor($app), true);
    }

    private static function column(string $app): int
    {
        return match ($app) {
            self::GUARD => 1,
            self::RESIDENT => 2,
            default => throw new \InvalidArgumentException("No app [{$app}]."),
        };
    }

    /* ------------------------------------------------------------------ */
    /* Access levels inside a household — Member, Dependent, Helper */
    /* ------------------------------------------------------------------ */

    /** An adult of the household: everything a resident may do, except managing its members (the primary's). */
    public const MEMBER = 'member';

    /** A child or an elderly relative: panic, their own e-pass, notices and maintenance. */
    public const DEPENDENT = 'dependent';

    /** Domestic staff: panic and their own e-pass to come and go. */
    public const HELPER = 'helper';

    /** @var list<string> */
    public const LEVELS_ALL = [self::MEMBER, self::DEPENDENT, self::HELPER];

    /**
     * WHICH LEVELS REACH WHICH RESIDENT APP ENDPOINT — every one of them, by catalogue name.
     *
     * A TOKEN HAS ONE SET OF ABILITIES FOR EVERYBODY IN A HOUSEHOLD, so this is not a column of the
     * grid above. Tokens are minted once, at sign-in, and a new capability would be missing from every
     * token already in the field; so the level is checked on EVERY REQUEST, by `EnforceAccessLevel`,
     * against the register entry the account signs in as — and a change of level takes effect on the
     * very next request, with nobody signed out.
     *
     * THIS IS THE WHOLE TRUTH, row for row, and `AccessLevelsTest` fails if an endpoint is missing or
     * a row names one that does not exist. The first ten are reachable by every level for reasons of
     * their own: an account still waiting on its claim reaches the six pending ones, `me.show` is where
     * the app learns the level, the app stores require account deletion, `panic.contacts` is the
     * panic screen's "text my emergency contacts" — PANIC IS NEVER REMOVED FROM ANYBODY — and the three
     * live doors narrow by level channel by channel instead.
     *
     * Where a cell says less than the grid above allows, the level NARROWS a household's right; it
     * never widens one. Voting stays one paper per unit (`Governance::eligibility`): a level only
     * decides which of the unit's accounts may cast it.
     *
     * @var array<string, list<string>>
     */
    public const LEVELS = [
        // Every level, always.
        'auth.sign_out' => self::LEVELS_ALL,
        'push.register' => self::LEVELS_ALL,
        'push.unregister' => self::LEVELS_ALL,
        'auth.claim_unit' => self::LEVELS_ALL,
        'me.show' => self::LEVELS_ALL,
        'me.delete_account' => self::LEVELS_ALL,
        // A person's own sign-ins are theirs whatever their level: seeing and ending them is signing out, elsewhere.
        'me.sessions' => self::LEVELS_ALL,
        'me.sessions.end' => self::LEVELS_ALL,
        'me.sessions.end_others' => self::LEVELS_ALL,
        // "Face ID / fingerprint" on their own phone (M49): signing in is everybody's, whatever their level.
        'me.device_key.store' => self::LEVELS_ALL,
        'me.device_key.destroy' => self::LEVELS_ALL,
        // How the news reaches them (board 20, M50 §4.2 #4): their own, whatever their level, as the push switches are.
        'me.notifications.email.show' => self::LEVELS_ALL,
        'me.notifications.email.update' => self::LEVELS_ALL,
        // A person's own profile photo, whatever their level (26 Sep).
        'me.photo' => self::LEVELS_ALL,
        'me.photo.store' => self::LEVELS_ALL,
        'me.photo.remove' => self::LEVELS_ALL,
        // A copy of everything the platform holds about the person (M50 §4.3): theirs, whatever their level — and what
        // it shows of the household narrows by level inside the file, as the app does.
        'me.data_export' => self::LEVELS_ALL,
        // Asking Gemini Security for help on Live chat (M50 §4.3): anybody signed in, whatever their level.
        'support.contact' => self::LEVELS_ALL,
        'support.chat.show' => self::LEVELS_ALL,
        'support.chat.messages.store' => self::LEVELS_ALL,
        // What the person's own account has been shown once — the first-run tour (M47 §3): theirs, whatever their level.
        'me.shown' => self::LEVELS_ALL,
        'panic.contacts' => self::LEVELS_ALL,
        // Live updates' own two doors (M11 Part C). They hand out nothing by themselves: each channel's rule
        // asks the person's level again, and a level is granted only the channels whose every read it reaches
        // (`HouseholdRule::levelReaches`) — so these carry the person's own channel, panic's, to every level.
        'live.config' => self::LEVELS_ALL,
        'live.auth' => self::LEVELS_ALL,
        'live.catch_up' => self::LEVELS_ALL,

        // Panic and the person's own entry — never removed.
        'alerts.store' => self::LEVELS_ALL,
        'duress.store' => self::LEVELS_ALL,
        'duress.cancel' => self::LEVELS_ALL,
        'duress.show' => self::LEVELS_ALL,
        // Writing to the control room on one's own panic (M50 §4.2 #2) is part of the panic: never removed either.
        'duress.messages.store' => self::LEVELS_ALL,
        'duress.audio.store' => self::LEVELS_ALL,
        'me.update' => self::LEVELS_ALL,
        'epass.show' => self::LEVELS_ALL,

        // The household's people, cars and contacts: read by Members and Dependents, kept by Members.
        'me.household' => [self::MEMBER, self::DEPENDENT],
        'members.index' => [self::MEMBER, self::DEPENDENT],
        'members.store' => [self::MEMBER],
        'members.invite' => [self::MEMBER],
        'members.invite_cancel' => [self::MEMBER],
        'members.level' => [self::MEMBER],
        // A Helper's days, hours, end date and pause (8 Oct 2026): the primary's, as every change to the household's people is.
        'members.access' => [self::MEMBER],
        'members.destroy' => [self::MEMBER],
        'vehicles.index' => [self::MEMBER, self::DEPENDENT],
        'vehicles.store' => [self::MEMBER],
        // Removing is changing the household's list, as adding is: a Member's act (STAGE2 §3.2: Y N N).
        'vehicles.destroy' => [self::MEMBER],
        // Changing a vehicle in place, and the household's pets (M50 §5.3): read as the cars are, kept as they are.
        'vehicles.update' => [self::MEMBER],
        'pets.index' => [self::MEMBER, self::DEPENDENT],
        'pets.store' => [self::MEMBER],
        'pets.update' => [self::MEMBER],
        'pets.destroy' => [self::MEMBER],
        'contacts.index' => [self::MEMBER, self::DEPENDENT],
        'contacts.store' => [self::MEMBER],
        'contacts.update' => [self::MEMBER],
        'contacts.destroy' => [self::MEMBER],

        // Letting somebody in on the household's word.
        'visitor_passes.index' => [self::MEMBER],
        'visitor_passes.store' => [self::MEMBER],
        'visitor_passes.update' => [self::MEMBER],
        'visitor_passes.cancel' => [self::MEMBER],
        'visitor_passes.share' => [self::MEMBER],
        'approvals.respond' => [self::MEMBER],

        // The household's money.
        'invoices.index' => [self::MEMBER],
        'households.balance' => [self::MEMBER],
        'households.statement' => [self::MEMBER],
        'payments.index' => [self::MEMBER],
        'payments.receipt' => [self::MEMBER],
        'invoices.pay_intent' => [self::MEMBER],
        // The test-card checkout (M12 §2.6): whoever may see the household's money may pay it.
        'payments.card' => [self::MEMBER],
        // A card payment through the gateway (M16 §2.2): the same rule — whoever may see the money may pay it.
        'payments.intents.store' => [self::MEMBER],
        'payments.intents.show' => [self::MEMBER],
        // AutoPay and saved cards (M50 §4.2 #3): the same rule — whoever may see the household's money may pay it.
        'autopay.show' => [self::MEMBER],
        'autopay.store' => [self::MEMBER],
        'autopay.destroy' => [self::MEMBER],
        'cards.index' => [self::MEMBER],
        'cards.store' => [self::MEMBER],
        'cards.default' => [self::MEMBER],
        'cards.destroy' => [self::MEMBER],

        // Maintenance and security reports, and the estate's notices.
        'tickets.index' => [self::MEMBER, self::DEPENDENT],
        'tickets.store' => [self::MEMBER, self::DEPENDENT],
        'tickets.media' => [self::MEMBER, self::DEPENDENT],
        // Answering "is it fixed?" on the household's own request (26 Sep) — whoever may file one may answer it.
        'tickets.confirm_fixed' => [self::MEMBER, self::DEPENDENT],
        'tickets.still_a_problem' => [self::MEMBER, self::DEPENDENT],
        'tickets.comment' => [self::MEMBER, self::DEPENDENT],
        'notices.index' => [self::MEMBER, self::DEPENDENT],
        'notices.attachment' => [self::MEMBER, self::DEPENDENT],
        'notices.read' => [self::MEMBER, self::DEPENDENT],

        // Governance and the vote.
        'meetings.index' => [self::MEMBER],
        'meetings.minutes' => [self::MEMBER],
        'meetings.rsvp' => [self::MEMBER],
        'elections.index' => [self::MEMBER],
        'elections.eligibility' => [self::MEMBER],
        'elections.ballot' => [self::MEMBER],
        'elections.results' => [self::MEMBER],

        // Amenities, which carry fees and deposits.
        'amenities.index' => [self::MEMBER],
        'bookings.index' => [self::MEMBER],
        'bookings.store' => [self::MEMBER],
        'bookings.cancel' => [self::MEMBER],
        'bookings.pay_card' => [self::MEMBER],
        'bookings.quote' => [self::MEMBER],
        'bookings.book' => [self::MEMBER],
    ];

    /** @var array<string, true> the unlisted endpoints already reported by this process */
    private static array $unlistedReported = [];

    /**
     * Whether a person at this level may reach this endpoint.
     *
     * IT FAILS CLOSED. An endpoint with no row is refused — a merge that forgot its row must not open
     * an endpoint to every level by omission — and it is reported, once per process. The one exception
     * is an endpoint whose catalogue capability is `alerts`: panic and duress, which are never taken
     * from anybody, so an unlisted one is allowed (and still reported, so the row gets written).
     *
     * `$capability` is the catalogue's own for that endpoint (`Catalogue::endpoints()[$e]['capability']`).
     */
    public static function levelAllows(string $level, string $endpoint, ?string $capability = null): bool
    {
        if (! array_key_exists($endpoint, self::LEVELS)) {
            self::reportUnlisted($endpoint, $capability);

            return $capability === 'alerts';
        }

        $decided = in_array($level, self::LEVELS[$endpoint], true);

        /*
         * THE ESTATE'S OWN RULES (8 Oct 2026). What a Dependent or a Helper may do is the estate's to change, from its
         * console, without a release — within limits nobody moves (AccessRules: a Member is never narrowed; panic, the
         * person's own e-pass and managing the household's people are not in any group, so nothing is read for them).
         * An estate that has set nothing gets exactly this table.
         */
        if ($level === self::MEMBER) {
            return $decided;
        }

        return AccessRules::override($level, $endpoint) ?? $decided;
    }

    private static function reportUnlisted(string $endpoint, ?string $capability): void
    {
        if (isset(self::$unlistedReported[$endpoint])) {
            return;
        }

        self::$unlistedReported[$endpoint] = true;

        report(new \RuntimeException(sprintf(
            'Resident App endpoint [%s] has no row in AppMatrix::LEVELS, so it is %s at every access level. Add its row.',
            $endpoint,
            $capability === 'alerts' ? 'allowed (panic is never removed)' : 'refused',
        )));
    }
}
