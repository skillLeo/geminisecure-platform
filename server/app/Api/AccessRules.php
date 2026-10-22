<?php

declare(strict_types=1);

namespace App\Api;

use App\Models\Estate\EstateSetting;
use App\Models\Estate\Resident;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\SiteTime;
use DomainException;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * WHAT A DEPENDENT AND A HELPER MAY DO, BY THE ESTATE'S OWN RULES — one settings place, changed from the estate's
 * console (Settings → Resident app access) without a release (the user, 8 Oct 2026).
 *
 * WHAT NOBODY CAN CHANGE (shown on the screen as fixed rows, and never looked up here):
 *   - a Member has everything — the household's adults;
 *   - the panic button and a person's own e-Pass are never taken from anybody;
 *   - only the primary resident adds or removes people, or changes their access;
 *   - signing in, their own profile and Live chat are everybody's.
 *
 * WHAT AN ESTATE MAY CHANGE, for a Dependent and for a Helper separately: the GROUPS below, each a set of endpoints
 * the app asks for together. A group left unset is the platform's default — `AppMatrix::LEVELS`, the decided matrix
 * — so an estate that never opens the screen behaves exactly as before. `AppMatrix::levelAllows` asks `override()`, so
 * every place that decides what a level may do (the per-request check, `me.show`'s `may`, the live channels, who is
 * pushed what, the data export) follows the estate's rules at once.
 *
 * AND A HELPER'S HOURS: the estate's default days and hours for a Helper (Mon–Sat, 7 AM–7 PM unless it says otherwise),
 * which one household may change for its own helper, with an end date and a "pause now" (`window()`); and who is
 * told when a Helper — or a Dependent — comes through the gate (`entryRecipients()`).
 *
 * Read with one query per estate, kept for thirty seconds; never on an endpoint outside the groups — so the panic
 * button never waits on, or fails with, this read.
 */
final class AccessRules
{
    /** The levels an estate's rules apply to. A Member is never narrowed. */
    public const LEVELS = [AppMatrix::DEPENDENT, AppMatrix::HELPER];

    /**
     * Every group an estate may open or close for a Dependent or a Helper, in the order the screen lists them: what
     * it is, the endpoints it is, and whether it touches money, security or privacy (the screen marks those).
     * `needs`: a group that makes no sense without another (paying without seeing what is owed) is closed with it.
     *
     * @var array<string, array{label: string, endpoints: list<string>, flag: string|null, needs?: string}>
     */
    public const GROUPS = [
        'household' => ['label' => 'See the household: its people, vehicles and emergency contacts', 'endpoints' => ['me.household', 'members.index', 'vehicles.index', 'pets.index', 'contacts.index'], 'flag' => 'privacy'],
        'household_edit' => ['label' => 'Change the household\'s vehicles and emergency contacts', 'endpoints' => ['vehicles.store', 'vehicles.update', 'vehicles.destroy', 'pets.store', 'pets.update', 'pets.destroy', 'contacts.store', 'contacts.update', 'contacts.destroy'], 'flag' => null, 'needs' => 'household'],
        'visitor_passes' => ['label' => 'Invite visitors (send visitor passes)', 'endpoints' => ['visitor_passes.index', 'visitor_passes.store', 'visitor_passes.update', 'visitor_passes.cancel', 'visitor_passes.share'], 'flag' => 'security'],
        'approvals' => ['label' => 'Answer the gate: let a walk-up visitor in, or not', 'endpoints' => ['approvals.respond'], 'flag' => 'security'],
        'dues' => ['label' => 'See the household\'s dues, statement and receipts', 'endpoints' => ['invoices.index', 'households.balance', 'households.statement', 'payments.index', 'payments.receipt'], 'flag' => 'money'],
        'payments' => ['label' => 'Pay dues and fees, save cards, turn on AutoPay', 'endpoints' => ['invoices.pay_intent', 'payments.card', 'payments.intents.store', 'payments.intents.show', 'autopay.show', 'autopay.store', 'autopay.destroy', 'cards.index', 'cards.store', 'cards.default', 'cards.destroy'], 'flag' => 'money', 'needs' => 'dues'],
        'tickets' => ['label' => 'Report maintenance and security problems', 'endpoints' => ['tickets.index', 'tickets.store', 'tickets.media', 'tickets.confirm_fixed', 'tickets.still_a_problem', 'tickets.comment'], 'flag' => null],
        'notices' => ['label' => 'Read the estate\'s notices', 'endpoints' => ['notices.index', 'notices.attachment', 'notices.read'], 'flag' => null],
        'meetings' => ['label' => 'See meetings, their minutes, and reply to them', 'endpoints' => ['meetings.index', 'meetings.minutes', 'meetings.rsvp'], 'flag' => null],
        'elections' => ['label' => 'Vote in elections (the home still has one ballot)', 'endpoints' => ['elections.index', 'elections.eligibility', 'elections.ballot', 'elections.results'], 'flag' => null],
        'bookings' => ['label' => 'Book amenities (they carry fees and deposits)', 'endpoints' => ['amenities.index', 'bookings.index', 'bookings.store', 'bookings.cancel', 'bookings.pay_card', 'bookings.quote', 'bookings.book'], 'flag' => 'money'],
    ];

    /** The rows the screen shows as fixed, with why. @var list<array{label: string, member: string, dependent: string, helper: string, why: string}> */
    public const FIXED = [
        ['label' => 'Panic button', 'member' => 'Yes', 'dependent' => 'Yes', 'helper' => 'Yes, with the household\'s emergency contacts to text or call', 'why' => 'Panic is never taken from anybody.'],
        ['label' => 'Their own e-Pass at the gate', 'member' => 'Yes', 'dependent' => 'Yes', 'helper' => 'Yes, inside the helper\'s days and hours', 'why' => 'It is how they come home, or come to work.'],
        ['label' => 'Add or remove people, change their access', 'member' => 'The primary resident only', 'dependent' => 'No', 'helper' => 'No', 'why' => 'One person holds the household.'],
        ['label' => 'Their own profile, sign-in, Live chat with Gemini Security', 'member' => 'Yes', 'dependent' => 'Yes', 'helper' => 'Yes', 'why' => 'A person\'s own account is theirs.'],
    ];

    /** A Helper's days and hours when the estate and the household have set none: Monday to Saturday, 7 AM to 7 PM. */
    public const DEFAULT_HELPER_DAYS = [1, 2, 3, 4, 5, 6];

    public const DEFAULT_HELPER_FROM = '07:00';

    public const DEFAULT_HELPER_TO = '19:00';

    /** Who is told when somebody comes through the gate on their e-Pass. */
    public const RECIPIENTS = ['primary', 'members', 'nobody'];

    public const DEFAULT_ENTRY_RECIPIENTS = [AppMatrix::HELPER => 'primary', AppMatrix::DEPENDENT => 'nobody'];

    private const CACHE_SECONDS = 30;

    /** @var array<string, array{at: int, rules: array<string, mixed>}> */
    private static array $cache = [];

    /** endpoint => group, built once. @var array<string, string>|null */
    private static ?array $groupOf = null;

    /** The group an endpoint belongs to, or null — locked, or every level's — without touching the database. */
    public static function groupOf(string $endpoint): ?string
    {
        if (self::$groupOf === null) {
            self::$groupOf = [];
            foreach (self::GROUPS as $group => $spec) {
                foreach ($spec['endpoints'] as $name) {
                    self::$groupOf[$name] = $group;
                }
            }
        }

        return self::$groupOf[$endpoint] ?? null;
    }

    /** The platform's decided answer for a group at a level: the matrix's own, read off the group's first endpoint. */
    public static function platformDefault(string $level, string $group): bool
    {
        $first = self::GROUPS[$group]['endpoints'][0];

        return in_array($level, AppMatrix::LEVELS[$first] ?? [], true);
    }

    /**
     * What the estate says about this endpoint at this level, or null for "the platform's default". A Member, a locked
     * endpoint or one every level reaches is null without a read; a group that needs another is closed with it.
     */
    public static function override(string $level, string $endpoint): ?bool
    {
        if (! in_array($level, self::LEVELS, true) || ($group = self::groupOf($endpoint)) === null) {
            return null;
        }

        return self::allows($level, $group);
    }

    /** Whether this level has this group here — the estate's word, else the platform's — with what it needs. */
    public static function allows(string $level, string $group): bool
    {
        $rules = self::current();
        $set = $rules[$level][$group] ?? null;
        $open = is_bool($set) ? $set : self::platformDefault($level, $group);
        $needs = self::GROUPS[$group]['needs'] ?? null;

        return $open && ($needs === null || self::allows($level, $needs));
    }

    /**
     * The estate's rules, as stored — an empty array when it has set none, or none can be read (then every answer is
     * the platform's default: never wider than decided, because the defaults ARE the decided matrix).
     *
     * @return array<string, mixed>
     */
    public static function current(): array
    {
        $tenant = function_exists('tenant') ? (string) (tenant()?->getTenantKey() ?? '') : '';

        if ($tenant === '') {
            return [];
        }

        $kept = self::$cache[$tenant] ?? null;
        if ($kept !== null && time() - $kept['at'] < self::CACHE_SECONDS) {
            return $kept['rules'];
        }

        try {
            $rules = (array) (EstateSetting::current()->resident_access_rules ?? []);
        } catch (Throwable $e) {
            report($e);
            $rules = [];
        }

        self::$cache[$tenant] = ['at' => time(), 'rules' => $rules];

        return $rules;
    }

    /** Forget what was read — after a save, and between tests. */
    public static function forget(): void
    {
        self::$cache = [];
    }

    /**
     * The whole board for the console: every group with both levels' answers and whether each is the default, the
     * fixed rows, a Helper's default days and hours, and who is told of an entry.
     *
     * @return array<string, mixed>
     */
    public static function board(): array
    {
        $rules = self::current();

        return [
            'groups' => array_map(static fn (string $group): array => [
                'key' => $group,
                'label' => self::GROUPS[$group]['label'],
                'flag' => self::GROUPS[$group]['flag'],
                // The group it cannot be had without, in words: "Pay dues…" needs "See the household's dues…".
                'needs' => isset(self::GROUPS[$group]['needs']) ? self::GROUPS[self::GROUPS[$group]['needs']]['label'] : null,
                'dependent' => self::allows(AppMatrix::DEPENDENT, $group),
                'helper' => self::allows(AppMatrix::HELPER, $group),
                'dependent_default' => self::platformDefault(AppMatrix::DEPENDENT, $group),
                'helper_default' => self::platformDefault(AppMatrix::HELPER, $group),
            ], array_keys(self::GROUPS)),
            'fixed' => self::FIXED,
            'helper_days' => self::days($rules['helper_days'] ?? null) ?? self::DEFAULT_HELPER_DAYS,
            'helper_from' => self::clock($rules['helper_from'] ?? null) ?? self::DEFAULT_HELPER_FROM,
            'helper_to' => self::clock($rules['helper_to'] ?? null) ?? self::DEFAULT_HELPER_TO,
            'entry' => [
                AppMatrix::HELPER => self::entryRecipients(AppMatrix::HELPER),
                AppMatrix::DEPENDENT => self::entryRecipients(AppMatrix::DEPENDENT),
            ],
        ];
    }

    /**
     * Save the estate's rules from the console — every value checked, the old and new audited.
     *
     * @param  array<string, mixed>  $input  {dependent: {group: bool}, helper: {group: bool}, helper_days, helper_from, helper_to, entry: {helper, dependent}}
     *
     * @throws DomainException in words the console shows
     */
    public static function save(array $input, User $by): void
    {
        $rules = [];

        foreach (self::LEVELS as $level) {
            foreach (array_keys(self::GROUPS) as $group) {
                $value = $input[$level][$group] ?? null;
                if (is_bool($value) && $value !== self::platformDefault($level, $group)) {
                    $rules[$level][$group] = $value;
                }
            }
        }

        $days = self::days($input['helper_days'] ?? null);
        $from = self::clock($input['helper_from'] ?? null);
        $to = self::clock($input['helper_to'] ?? null);

        if ($days === null || $days === []) {
            throw new DomainException('Choose at least one day a helper may come.');
        }

        if ($from === null || $to === null || $from === $to) {
            throw new DomainException('Choose the hours a helper may come: a start and an end, not the same time.');
        }

        $rules['helper_days'] = $days;
        $rules['helper_from'] = $from;
        $rules['helper_to'] = $to;

        foreach (self::DEFAULT_ENTRY_RECIPIENTS as $level => $default) {
            $who = $input['entry'][$level] ?? $default;
            if (! in_array($who, self::RECIPIENTS, true)) {
                throw new DomainException('Choose who is told when somebody comes in: the primary resident, every Member, or nobody.');
            }
            $rules['entry'][$level] = $who;
        }

        $settings = EstateSetting::current();
        $before = (array) ($settings->resident_access_rules ?? []);
        $settings->forceFill(['resident_access_rules' => $rules])->save();
        self::forget();

        app(AuditLogger::class)->record(
            action: 'estate.resident_access_rules_changed',
            entityType: 'EstateSetting',
            entityId: (string) $settings->id,
            before: ['rules' => $before],
            after: ['rules' => $rules],
            tenantId: (string) tenant()?->getTenantKey(),
            actor: $by,
        );
    }

    /** Who is told when somebody at this level comes through the gate on their e-Pass: `primary`, `members` or `nobody`. */
    public static function entryRecipients(string $level): string
    {
        $who = self::current()['entry'][$level] ?? null;

        return in_array($who, self::RECIPIENTS, true) ? $who : (self::DEFAULT_ENTRY_RECIPIENTS[$level] ?? 'nobody');
    }

    /* ------------------------------------------------------------------ a helper's days and hours */

    /**
     * One Helper's window: their household's own days, hours and end date where set, else the estate's default — and
     * whether the household has paused them.
     *
     * @return array{days: list<int>, from: string, to: string, until: string|null, paused: bool, own: bool}
     */
    public static function window(Resident $helper): array
    {
        $rules = self::current();
        $ownDays = self::days($helper->access_days);
        $own = $ownDays !== null || $helper->access_from !== null || $helper->access_to !== null || $helper->access_until !== null;

        return [
            'days' => $ownDays ?? self::days($rules['helper_days'] ?? null) ?? self::DEFAULT_HELPER_DAYS,
            'from' => self::clock($helper->access_from) ?? self::clock($rules['helper_from'] ?? null) ?? self::DEFAULT_HELPER_FROM,
            'to' => self::clock($helper->access_to) ?? self::clock($rules['helper_to'] ?? null) ?? self::DEFAULT_HELPER_TO,
            'until' => $helper->access_until?->toDateString(),
            'paused' => $helper->access_paused_at !== null,
            'own' => $own,
        ];
    }

    /**
     * Why a Helper may not come in at this moment, or null when they may: `paused`, `ended` (after their end date),
     * `day` (not one of their days), `before` or `after` (their hours). Read on the estate's clock.
     *
     * @param  array{days: list<int>, from: string, to: string, until: string|null, paused: bool}  $window
     */
    public static function refusedAt(array $window, Carbon $at, ?string $tenantId = null): ?string
    {
        if ($window['paused']) {
            return 'paused';
        }

        $local = SiteTime::local($at, $tenantId);

        if ($window['until'] !== null && $local->toDateString() > $window['until']) {
            return 'ended';
        }

        $time = $local->format('H:i');
        $overnight = $window['from'] > $window['to'];
        // An overnight window (a night carer, 8 PM to 6 AM) belongs to the day it starts on.
        $day = $overnight && $time < $window['to'] ? $local->copy()->subDay()->dayOfWeekIso : $local->dayOfWeekIso;

        if (! in_array($day, $window['days'], true)) {
            return 'day';
        }

        if ($overnight) {
            return $time >= $window['from'] || $time < $window['to'] ? null : ($time < $window['from'] ? 'before' : 'after');
        }

        return $time < $window['from'] ? 'before' : ($time >= $window['to'] ? 'after' : null);
    }

    /** "Mon–Sat, 7:00 AM – 7:00 PM" — and ", until Oct 31, 2026" when it has an end. */
    public static function windowLine(array $window): string
    {
        $line = self::daysWords($window['days']).', '.self::clockWords($window['from']).' – '.self::clockWords($window['to']);

        return $window['until'] === null ? $line : $line.', until '.Carbon::parse($window['until'])->format('M j, Y');
    }

    /** [1,2,3,4,5,6] → "Mon–Sat"; [1,3,5] → "Mon, Wed, Fri"; all seven → "Every day". */
    public static function daysWords(array $days): string
    {
        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
        $days = array_values(array_unique(array_map('intval', $days)));
        sort($days);

        if (count($days) === 7) {
            return 'Every day';
        }

        $run = count($days) >= 3 && $days === range($days[0], $days[count($days) - 1]);

        return $run ? $names[$days[0]].'–'.$names[$days[count($days) - 1]] : implode(', ', array_map(static fn (int $d): string => $names[$d] ?? '?', $days));
    }

    /** "07:00" → "7:00 AM". */
    public static function clockWords(string $hhmm): string
    {
        return Carbon::createFromFormat('H:i', $hhmm)->format('g:i A');
    }

    /** @return list<int>|null the days 1 (Mon)–7 (Sun), in order, or null when none are given or any is not a day */
    public static function days(mixed $days): ?array
    {
        if (! is_array($days) || $days === []) {
            return null;
        }

        $clean = [];
        foreach ($days as $day) {
            if (! is_numeric($day) || (int) $day < 1 || (int) $day > 7) {
                return null;
            }
            $clean[] = (int) $day;
        }

        $clean = array_values(array_unique($clean));
        sort($clean);

        return $clean;
    }

    /** "7:00" or "07:00" → "07:00" on the half hour; null when it is not a time on the half hour. */
    public static function clock(mixed $time): ?string
    {
        if (! is_string($time) || preg_match('/^([01]?\d|2[0-3]):(00|30)$/', trim($time), $m) !== 1) {
            return null;
        }

        return str_pad($m[1], 2, '0', STR_PAD_LEFT).':'.$m[2];
    }
}
