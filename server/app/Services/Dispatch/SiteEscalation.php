<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Enums\Console;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * THE SITE'S ESCALATION CONTACT — the last tier of a panic (M6 §2.1 part 3).
 *
 * WHAT THE PLATFORM ALREADY HAD, AND WHAT IT DID NOT.
 * ==================================================
 * There is no field anywhere called "escalation contact", and nothing that reads
 * like one: `DispatchController` says in its own words that there is "no escalation
 * record at all", and the two board buttons that would have used one are rendered
 * disabled with that reason attached. A search for a site emergency number finds
 * `resident_app` household contacts (a RESIDENT's own people, in the estate
 * database, and never the estate's) and a line of demonstration copy in a standing
 * orders wireframe. So rather than invent a table and a settings screen nobody
 * asked for, this reads the two lists of people the platform ALREADY keeps for a
 * site, in the order a control room would actually work down. Confirmed as Q-M41.
 *
 *   1. THE HEADS OF SECURITY WHO COVER THIS SITE. `Role::HEAD_OF_SECURITY` is the
 *      only Gemini role scoped to assigned sites, and it is already the platform's
 *      answer to "who is accountable for what happens at this estate" — it is who a
 *      supervisor override is reported to (`SupervisorOverride::report`). An
 *      emergency at a site with no officer on it is the same person's problem.
 *
 *   2. FAILING THAT, THE ESTATE'S OWN NAMED CONTACTS. `estate_assignments` joined to
 *      `users` with an estate-console role is what `ClientDirectory` already calls
 *      the client's contacts and its primary contact, President first. These are the
 *      community's own committee: not security responders, but the people the
 *      platform holds for that site, and better than nobody.
 *
 * NEITHER IS A HANDSET, AND THAT IS THE POINT. Both lists are CONSOLE users. They
 * have no push address (`SupervisorOverride` says the same), so reaching them is a
 * mail and the console's own bell, and it is NEVER counted as a handset in what the
 * resident is told. `alerted_handsets` stays 0 on this tier; the resident is told
 * plainly that no handset was reached AND that the estate's escalation contact has
 * been told. Saying "help is coming" because an email was sent would be exactly the
 * lie M6 §2.1 part 5 forbids.
 *
 * NOTHING HERE THROWS. It is read on the path of a panic; a lookup that fails
 * returns an empty list and the resident is told that nobody was reached, which is
 * the truthful answer when the platform cannot find anybody.
 */
final class SiteEscalation
{
    /** At most this many people are mailed for one alert — an escalation, not a broadcast. */
    public const MAX_CONTACTS = 10;

    /**
     * Who to tell when a panic at this site reached no handset at all.
     *
     * KEPT AS IT WAS, and still the fallback reading: the Head of Security if
     * there is one, the estate's committee if there is not. Nothing else calls
     * it any more — `allContactsFor()` below is what the panic fan-out uses —
     * but it is the honest answer to the question it asks, and removing it would
     * change behaviour for any future caller that means exactly this.
     *
     * @return Collection<int, User>
     */
    public function contactsFor(string $tenantId): Collection
    {
        if ($tenantId === '') {
            return new Collection;
        }

        $heads = $this->headsOfSecurity($tenantId);

        return $heads->isNotEmpty() ? $heads : $this->estateCommittee($tenantId);
    }

    /**
     * EVERYONE responsible for this site, told at once — M10 §1.
     *
     * `contactsFor()` chose: the Head of Security, and only if there was none,
     * the estate's committee. That was a second gate hiding inside the leg §1
     * un-gates, and it produced exactly the outcome the client objected to — a
     * panic at a lot, a security company officer told, and the property office
     * that would have to deal with a medical emergency or a fire never hearing
     * about it because somebody else was covered.
     *
     * §1.2 names both as separate destinations: "The Gemini Head of Security for
     * that site" and "The estate's own Head of Security". They are different
     * people in different organisations and they are told together.
     *
     * THE REST OF THE COMMITTEE IS CAPPED at `MAX_CONTACTS`; BOTH HEADS OF SECURITY
     * NEVER ARE. This is an escalation, not a broadcast: a site with a large committee
     * should not turn one panic into forty emails. But the two Heads of Security are
     * §1.2's items 4 and 5 by name, and a cap over one list that sorted the estate's
     * Head of Security LAST (its role sorts at 70, after every other estate role)
     * dropped them the day two Gemini Heads of Security and eight committee members
     * filled the places (bug hunt B092) — with no SMS provider, their only alert away
     * from the console. So the cap bounds only the people it exists for.
     *
     * WHO IS RESPONSIBLE, NOT WHO MAY BE MAILED. The fan-out does not mail this
     * list: it mails `alertRecipientsFor()`, which is the same people with every
     * one who has not proven an alert address set aside (M10 §1.4).
     *
     * @return Collection<int, User>
     */
    public function allContactsFor(string $tenantId): Collection
    {
        if ($tenantId === '') {
            return new Collection;
        }

        [$heads, $committee] = $this->headsAndCommittee($tenantId);

        return $heads->concat($committee->take(self::MAX_CONTACTS))->values();
    }

    /**
     * WHO THE ALERT MAIL MAY ACTUALLY GO TO, AND WHO IT MAY NOT — M10 §1.4.
     *
     * The same people as `allContactsFor()` — the Gemini Head of Security for the
     * site, the estate's own Head of Security, the estate's committee — split by the
     * one question an alert mail has to ask of each: `alertEmail()`. "An unverified
     * address is never used for alerting", so a person with no PROVEN address is not
     * mailed at their sign-in address, or at a contact address they typed and never
     * confirmed. They are returned separately, so the fan-out can write down that
     * they were not mailed and why, instead of counting them as told.
     *
     * THE CAP COUNTS PEOPLE MAILED, NOT PEOPLE CONSIDERED. Capping first and filtering
     * after would let ten unproven Presidents listed ahead of a proven Secretary use up
     * every place and mail nobody. So every list is read without its limit here — the
     * Heads of Security who cover one site and one estate's own console accounts, each
     * bounded by the site — split, and only then capped, on the side that is mailed.
     * The unproven are not capped: they are a count in a note, never a mail.
     *
     * AND THE CAP IS THE COMMITTEE'S (bug hunt B092): every proven Head of Security —
     * Gemini's for this site, and the estate's own, by lineage — is mailed outside it,
     * because §1.2 names both and a cap over one list dropped whoever sorted last.
     *
     * NOTHING HERE THROWS, for this class's reason: each list already answers empty
     * on failure, and the panic is told the truth about an empty list.
     *
     * @return array{mail: Collection<int, User>, unverified: Collection<int, User>}
     */
    public function alertRecipientsFor(string $tenantId): array
    {
        if ($tenantId === '') {
            return ['mail' => new Collection, 'unverified' => new Collection];
        }

        [$heads, $committee] = $this->headsAndCommittee($tenantId);

        $proven = static fn (User $user): bool => $user->alertEmail() !== null;

        [$provenHeads, $unprovenHeads] = $heads->partition($proven);
        [$provenCommittee, $unprovenCommittee] = $committee->partition($proven);

        return [
            'mail' => $provenHeads->concat($provenCommittee->take(self::MAX_CONTACTS))->values(),
            'unverified' => $unprovenHeads->concat($unprovenCommittee)->values(),
        ];
    }

    /**
     * Both Heads of Security, and everyone else on the estate's committee — each person
     * once, uncapped, in the order they are told: Gemini's Heads of Security for this
     * site, the estate's own Head of Security, then the committee President first.
     *
     * The same person can be on two lists — an estate committee member who also holds a
     * Gemini role, or the estate's Head of Security, who is a committee account too —
     * and one panic is one email.
     *
     * @return array{0: Collection<int, User>, 1: Collection<int, User>}
     */
    private function headsAndCommittee(string $tenantId): array
    {
        $heads = $this->headsOfSecurity($tenantId, limit: null)
            ->concat($this->estateHeadsOfSecurity($tenantId, limit: null))
            ->unique(static fn (User $user) => $user->getKey())
            ->values();

        $headIds = $heads->map(static fn (User $user): int => (int) $user->getKey())->all();

        $committee = $this->estateCommittee($tenantId, limit: null)
            ->reject(static fn (User $user): bool => in_array((int) $user->getKey(), $headIds, true))
            ->values();

        return [$heads, $committee];
    }

    /**
     * Every active Head of Security who may reach this estate.
     *
     * `canAccessEstate` rather than a join on `estate_assignments`: the scope is a
     * property of the ROLE (D-015), and a Head of Security is the one Gemini role
     * that is narrowed by assignment — reading the rows directly would get the
     * asymmetry wrong in the dangerous direction.
     *
     * `$limit` as `estateCommittee()` has it: capped for every caller but
     * `alertRecipientsFor()`, which caps after the unproven are set aside.
     *
     * @return Collection<int, User>
     */
    public function headsOfSecurity(string $tenantId, ?int $limit = self::MAX_CONTACTS): Collection
    {
        try {
            // `canAccessEstate`'s rule, asked of every Head of Security in one read (M11 A2) rather
            // than two reads each — this is on the path of every panic.
            $heads = User::whoCanAccessEstate(
                User::query()->role(Role::HEAD_OF_SECURITY, 'web')->where('status', 'active')->get(),
                $tenantId,
            );

            return ($limit === null ? $heads : $heads->take($limit))->values();
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);

            return new Collection;
        }
    }

    /**
     * The estate's own Head of Security (M10 §1.2 item 5, §2) — every active person with an
     * active assignment AT THIS ESTATE whose role there is that role or a copy of it.
     *
     * BY LINEAGE, NOT BY NAME (bug hunt B139). The shared Head of Security cannot be
     * changed from an estate, and the console tells the estate what to do instead: "Copy
     * it to make a version for this community." A copy has a name of its own
     * (`estate.custom.<estate>.<slug>`), so an estate that followed that advice and moved
     * its Head of Security onto the copy stopped texting them, and the record said the
     * estate had none. A copy inherits what binds its original — `Role::derivesFrom()`,
     * D-144's Ruling 1 — and being the estate's Head of Security is the same kind of fact.
     *
     * `$limit` as `estateCommittee()` has it.
     *
     * @return Collection<int, User>
     */
    public function estateHeadsOfSecurity(string $tenantId, ?int $limit = self::MAX_CONTACTS): Collection
    {
        try {
            $roles = self::estateHeadOfSecurityRoles($tenantId);

            if ($roles === []) {
                return new Collection;
            }

            $ids = DB::connection('mysql')
                ->table('estate_assignments')
                ->join('users', 'users.id', '=', 'estate_assignments.user_id')
                ->where('estate_assignments.tenant_id', $tenantId)
                ->where('estate_assignments.is_active', true)
                ->where('users.status', 'active')
                ->whereIn('estate_assignments.role_id', $roles)
                ->orderBy('estate_assignments.id')
                ->when($limit !== null, static fn ($query) => $query->limit((int) $limit))
                ->pluck('users.id')
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            if ($ids === []) {
                return new Collection;
            }

            $order = array_flip($ids);

            /** @var Collection<int, User> $users */
            $users = User::query()->whereIn('id', $ids)->get()->sortBy(
                static fn (User $user): int => $order[(int) $user->getKey()] ?? PHP_INT_MAX,
            )->values();

            return $users;
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);

            return new Collection;
        }
    }

    /**
     * The roles that make somebody the estate's own Head of Security AT THIS ESTATE: the
     * shared role, and any copy of it this estate made (a copy's `copied_from_id` always
     * names the system role at the root of its lineage, so one hop is all of it). Another
     * estate's copy is that estate's business and is never on this list.
     *
     * Never throws, for this class's reason: an empty list is the answer the panic path
     * can survive.
     *
     * @return list<int>
     */
    public static function estateHeadOfSecurityRoles(string $tenantId): array
    {
        try {
            $shared = Role::query()->where('name', Role::ESTATE_HEAD_OF_SECURITY)->value('id');

            if ($shared === null) {
                return [];
            }

            return Role::query()
                ->where(static function ($roles) use ($shared, $tenantId): void {
                    $roles->whereKey((int) $shared)
                        ->orWhere(static function ($copies) use ($shared, $tenantId): void {
                            $copies->where('copied_from_id', (int) $shared)
                                ->where(static fn ($owner) => $owner->whereNull('tenant_id')->orWhere('tenant_id', $tenantId));
                        });
                })
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->values()
                ->all();
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);

            return [];
        }
    }

    /**
     * The estate's own named people — the President first, then the rest of the
     * committee in the order `ClientDirectory` already lists them.
     *
     * `$limit` is `MAX_CONTACTS` for every caller but one: `alertRecipientsFor()`
     * reads the whole committee and caps AFTER setting aside the people with no
     * proven address, so that they cannot use up the places (M10 §1.4).
     *
     * @return Collection<int, User>
     */
    public function estateCommittee(string $tenantId, ?int $limit = self::MAX_CONTACTS): Collection
    {
        try {
            $ids = DB::connection('mysql')
                ->table('estate_assignments')
                ->join('users', 'users.id', '=', 'estate_assignments.user_id')
                ->join('roles', 'roles.id', '=', 'estate_assignments.role_id')
                // What each role stands for: this estate's own version of the President is still the President (M11 B4).
                ->leftJoin('roles as stands', 'stands.id', '=', 'roles.version_of_id')
                ->where('estate_assignments.tenant_id', $tenantId)
                ->where('estate_assignments.is_active', true)
                ->where('users.status', 'active')
                ->where('roles.console', Console::Estate->value)
                ->orderByRaw("CASE WHEN COALESCE(stands.name, roles.name) = '".Role::PRESIDENT."' THEN 0 ELSE 1 END")
                ->orderBy('roles.sort')
                ->orderBy('estate_assignments.id')
                ->when($limit !== null, static fn ($query) => $query->limit((int) $limit))
                ->pluck('users.id')
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->all();

            if ($ids === []) {
                return new Collection;
            }

            // Back in the order the join gave them: `whereIn` does not preserve it.
            $order = array_flip($ids);

            /** @var Collection<int, User> $users */
            $users = User::query()->whereIn('id', $ids)->get()->sortBy(
                static fn (User $user): int => $order[(int) $user->getKey()] ?? PHP_INT_MAX,
            )->values();

            return $users;
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);

            return new Collection;
        }
    }
}
