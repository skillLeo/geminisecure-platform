<?php

declare(strict_types=1);

namespace App\Api;

use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use DomainException;
use InvalidArgumentException;

/**
 * A household member's ACCESS LEVEL in the Resident App — Member, Dependent or Helper — read and
 * written in one place. What each level reaches is `AppMatrix::LEVELS`; this class answers whose
 * level is what, says it in words, and is the only writer of `residents.access_level`.
 *
 * NEVER WIDENED BY AN ERROR. The primary resident is always a Member, whatever the column says: they
 * hold the household. A register entry that cannot be found — an account whose `resident_id` points
 * nowhere — is the NARROWEST level, a Helper, so a broken link can only ever take rights away, and
 * panic and the person's own e-pass are never among them.
 */
final class AccessLevels
{
    public const PRIMARY_IS_MEMBER = 'The primary resident always has Member access.';

    /** Why nobody can be given less than Member yet — the office, the primary and the phone all read this. */
    // One line, the user's own words (M53 §6a). Never on screen once a server switches the levels on.
    public const NOT_OPEN = 'Dependent and Helper go live in the next update. Everyone is a Member until then.';

    /** @var array<string, string> */
    private const LABELS = [
        AppMatrix::MEMBER => 'Member',
        AppMatrix::DEPENDENT => 'Dependent',
        AppMatrix::HELPER => 'Helper',
    ];

    /**
     * The sentence the console and the phone both show under each level.
     *
     * A Helper's names what `panic.contacts` gives every level — the household's emergency contacts, to
     * text or call from the panic screen — because the office reads it before making somebody a Helper.
     *
     * @var array<string, string>
     */
    private const SENTENCES = [
        AppMatrix::MEMBER => 'An adult of the household: passes, dues, voting and everything else. Only the primary resident adds or removes people.',
        AppMatrix::DEPENDENT => 'A child or an elderly relative: the panic button, their own e-pass, notices and maintenance. No payments, passes or voting.',
        AppMatrix::HELPER => 'Domestic staff: the panic button, with the household\'s emergency contacts to text or call from it, and their own e-pass to come and go. Nothing else about the household, its money or its votes.',
    ];

    /**
     * What `me.show.access.may` reports, each key probed on ONE endpoint's row — so the phone hides
     * exactly what the server would refuse, and cannot drift from it.
     *
     * @var array<string, string>
     */
    public const MAY = [
        'household' => 'me.household',
        'household_edit' => 'contacts.store',
        'manage_members' => 'members.store',
        'visitor_passes' => 'visitor_passes.store',
        'approvals' => 'approvals.respond',
        'dues' => 'invoices.index',
        'payments' => 'invoices.pay_intent',
        'tickets' => 'tickets.store',
        'notices' => 'notices.index',
        'meetings' => 'meetings.index',
        'elections' => 'elections.index',
        'bookings' => 'bookings.index',
    ];

    /**
     * WHETHER ANYBODY MAY BE GIVEN LESS THAN MEMBER YET — `services.resident_app.access_levels`, OFF unless a
     * server turns it on.
     *
     * THE DEPLOY ORDER IS THE REASON. The Resident App in the field takes the panic screen's "text / call my
     * emergency contacts" people only from its copy of `me.household`, which a Helper is refused; the release
     * that reads `panic.contacts` comes after this server. A Helper made before it would open the panic screen
     * to an empty contacts sheet — and while no SMS provider is connected, that sheet is the only way a
     * household's contacts hear of a panic. Panic is never removed from anybody, so until the switch is on:
     *   - every writer refuses a level other than Member (`set()`, `members.store`, `members.invite`,
     *     `members.level`, the office's household page), in the words of `NOT_OPEN`;
     *   - taking a level back to Member is always allowed;
     *   - the matrix is still checked on every request, against whatever level each person holds.
     * Turn it on only once that release is out AND a claim approval carries the office's level choice (W-ACCEPT).
     */
    public static function open(): bool
    {
        return (bool) config('services.resident_app.access_levels', false);
    }

    /** Whether this level may be given to somebody now: Member always; Dependent and Helper once `open()`. */
    public static function mayGive(string $level): bool
    {
        return $level === AppMatrix::MEMBER || self::open();
    }

    /** The level a person has in the app. */
    public static function of(?Resident $resident): string
    {
        if ($resident === null) {
            return AppMatrix::HELPER;
        }

        if ($resident->is_primary) {
            return AppMatrix::MEMBER;
        }

        $level = (string) $resident->getAttribute('access_level');

        return in_array($level, AppMatrix::LEVELS_ALL, true) ? $level : AppMatrix::HELPER;
    }

    public static function label(string $level): string
    {
        return self::LABELS[$level] ?? self::LABELS[AppMatrix::HELPER];
    }

    public static function describe(string $level): string
    {
        return self::SENTENCES[$level] ?? self::SENTENCES[AppMatrix::HELPER];
    }

    /**
     * The three levels as a screen offers them, each with what it may do.
     *
     * @return list<array{value: string, label: string, sentence: string, may: array<string, bool>}>
     */
    public static function options(): array
    {
        return array_map(static fn (string $level): array => [
            'value' => $level,
            'label' => self::label($level),
            'sentence' => self::describe($level),
            'may' => self::may($level, false),
        ], AppMatrix::LEVELS_ALL);
    }

    /**
     * `me.show.access` — what this person may do, from the matrix the server enforces.
     *
     * A register entry that cannot be found reads as what the server treats it as — a Helper — rather
     * than as nothing, which the phone would draw optimistically as everything.
     *
     * @param  Household|null  $household  the account's household, for when the register entry is gone
     * @return array{level: string, level_label: string, is_primary: bool, primary_name: string|null, levels_open: bool, may: array<string, bool>}
     */
    public static function mayShape(?Resident $resident, ?Household $household = null): array
    {
        $level = self::of($resident);
        $isPrimary = (bool) $resident?->is_primary;

        return [
            'level' => $level,
            'level_label' => self::label($level),
            'is_primary' => $isPrimary,
            'primary_name' => self::primaryOf($resident !== null ? $resident->household : $household)?->full_name,
            // Whether the app may offer Dependent and Helper at all yet (`open()`).
            'levels_open' => self::open(),
            'may' => self::may($level, $isPrimary),
        ];
    }

    /**
     * The household's primary resident — who to ask to change somebody's access — or null when it has
     * none. `Household::primaryResident()` answers with SOMEBODY for a list screen even when nobody is
     * primary; a "who to ask" line must not name a person who cannot do it.
     */
    public static function primaryOf(?Household $household): ?Resident
    {
        $first = $household?->primaryResident();

        return $first !== null && $first->is_primary ? $first : null;
    }

    /**
     * The refusal a person reads when their level does not include an endpoint — what it is for, what
     * their access is, and who can change it.
     *
     *   "Dues and payments are for the household's Members. Your access in the Fletcher household is
     *    Dependent — ask Andrea Fletcher, or the estate office, if that should change."
     *
     * CHANGING the household's members is the PRIMARY resident's alone — a Member is refused it too
     * (`not_primary`) — so there the sentence names who can do it rather than inviting a level change
     * that would not help. READING the list is not: Members and Dependents read it, so a Helper refused
     * it is told, like any other refusal, who can change their access.
     */
    public static function refusal(string $endpoint, string $level, ?Household $household, ?Resident $primary): string
    {
        $where = $household === null || trim($household->name) === '' ? '' : ' in the '.trim($household->name);
        $access = self::topic($endpoint).' Your access'.$where.' is '.self::label($level).' — ';

        if (self::managesMembers($endpoint)) {
            return $access.($primary === null ? 'only the primary resident can do this; ask the estate office.' : 'only the primary resident, '.$primary->full_name.', can do this.');
        }

        return $access.($primary === null ? 'ask the estate office' : 'ask '.$primary->full_name.', or the estate office,').' if that should change.';
    }

    /** Adding, inviting, removing a member or changing their level — the primary's alone, at any level. */
    private static function managesMembers(string $endpoint): bool
    {
        return str_starts_with($endpoint, 'members.') && $endpoint !== 'members.index';
    }

    /** What an endpoint is for, said as who it belongs to. */
    private static function topic(string $endpoint): string
    {
        return match (true) {
            // Reading first: the member list is read by Members and Dependents, and only changed by the primary.
            in_array($endpoint, ['me.household', 'members.index', 'vehicles.index', 'pets.index', 'contacts.index'], true) => 'The household\'s members, vehicles and emergency contacts are for its Members and Dependents.',
            self::managesMembers($endpoint) => 'Adding, inviting and removing the household\'s members, and changing their access, is for the household\'s primary resident.',
            in_array($endpoint, ['vehicles.store', 'vehicles.update', 'vehicles.destroy', 'pets.store', 'pets.update', 'pets.destroy', 'contacts.store', 'contacts.update', 'contacts.destroy'], true) => 'Adding, changing and removing the household\'s vehicles and emergency contacts is for the household\'s Members.',
            str_starts_with($endpoint, 'visitor_passes.') => 'Visitor passes are issued by the household\'s Members.',
            $endpoint === 'approvals.respond' => 'Answering the gate for a visitor is for the household\'s Members.',
            str_starts_with($endpoint, 'invoices.'), str_starts_with($endpoint, 'households.'), str_starts_with($endpoint, 'payments.'), str_starts_with($endpoint, 'autopay.') => 'Dues and payments are for the household\'s Members.',
            str_starts_with($endpoint, 'tickets.') => 'Maintenance and security requests are for the household\'s Members and Dependents.',
            str_starts_with($endpoint, 'notices.') => 'Estate notices go to residents with Member or Dependent access.',
            str_starts_with($endpoint, 'meetings.') => 'Meetings are for the household\'s Members.',
            str_starts_with($endpoint, 'elections.') => 'Voting is for the household\'s Members — the household still has its one ballot.',
            $endpoint === 'amenities.index', str_starts_with($endpoint, 'bookings.') => 'Booking the estate\'s amenities is for the household\'s Members.',
            default => 'This part of the app is not included in your access.',
        };
    }

    /**
     * Change a person's level — the primary resident from the app, or the estate office.
     *
     * Checked on the next request the person makes (`EnforceAccessLevel`): nobody is signed out, and
     * nothing is kept in a token that could go stale. Audited with who, from where, and both levels.
     *
     * @param  string  $via  `resident_app`, `office` — where the change was made
     * @param  int|null  $byResidentId  the primary resident's own register entry, when they made it in the app
     * @param  int|null  $byAccountId  the Resident App account (the sign-in) they made it from
     * @return bool whether anything changed
     *
     * @throws DomainException for the primary resident, who is always a Member; and for a level other than
     *                         Member while levels are not open (`NOT_OPEN`)
     */
    public static function set(Resident $target, string $level, string $byName, ?User $byUser, string $via, ?int $byResidentId = null, ?int $byAccountId = null): bool
    {
        if (! in_array($level, AppMatrix::LEVELS_ALL, true)) {
            throw new InvalidArgumentException("No access level [{$level}].");
        }

        // The one writer, so every caller — the app, the office, a claim approval — meets the switch here.
        if (! self::mayGive($level)) {
            throw new DomainException(self::NOT_OPEN);
        }

        if ($target->is_primary) {
            throw new DomainException(self::PRIMARY_IS_MEMBER);
        }

        // From what is STORED, not from a copy held in memory: the audit says what the person really had.
        $stored = $target->exists
            ? Resident::withRemoved()->whereKey($target->getKey())->value('access_level')
            : $target->getAttribute('access_level');
        $before = in_array($stored, AppMatrix::LEVELS_ALL, true) ? (string) $stored : AppMatrix::HELPER;

        if ($stored === $level) {
            // Nothing to change — but a stale copy in memory is brought up to what is stored.
            $target->setAttribute('access_level', $level);
            $target->syncOriginalAttribute('access_level');

            return false;
        }

        $target->forceFill(['access_level' => $level])->save();

        app(AuditLogger::class)->record(
            action: 'household.member_level_changed',
            entityType: 'Resident',
            entityId: (string) $target->id,
            before: ['access_level' => $before],
            after: array_filter(
                ['access_level' => $level, 'household_id' => $target->household_id, 'by' => $byName, 'by_resident_id' => $byResidentId, 'by_account_id' => $byAccountId, 'via' => $via],
                static fn (mixed $value): bool => $value !== null,
            ),
            tenantId: (string) tenant()?->getTenantKey(),
            actor: $byUser,
        );

        return true;
    }

    /** @return array<string, bool> */
    private static function may(string $level, bool $isPrimary): array
    {
        $may = [];

        foreach (self::MAY as $key => $endpoint) {
            $may[$key] = AppMatrix::levelAllows($level, $endpoint) && ($key !== 'manage_members' || $isPrimary);
        }

        return $may;
    }
}
