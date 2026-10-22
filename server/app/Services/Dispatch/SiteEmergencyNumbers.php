<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Models\SiteEmergencyNumber;
use App\Support\JamaicanNumber;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * THE NUMBERS A PANIC AT THIS SITE IS TEXTED TO (Q-M09).
 *
 * "Additionally the SMS must be sent to: President (1-876-508-8082), Property Office
 * (1-876-224-4444), PPV1 Head of Security (1-876-845-9422) and Gemini Dispatch
 * (876-354-2119)."
 *
 * PER SITE, AND NOT IN THIS FILE. The four numbers above are Phoenix Park Village 1's,
 * and they are seeded as ROWS (`SiteEmergencyNumbersSeeder`). Another estate has a
 * different president and a different property office; a constant here would have to
 * be edited by a developer for every estate this platform ever signs, and would have
 * texted one community's committee about another community's emergency the first time
 * somebody forgot. `SiteEscalation` reads "who is accountable at this site" the same
 * way, from rows.
 *
 * THE ORDER IS THE ORDER THEY ARE TEXTED IN, and it is the client's own order, kept on
 * the row as `sort`. It costs nothing at four recipients and it is the order a control
 * room would read out.
 *
 * NOTHING HERE THROWS. It is read on the path of a panic. A lookup that fails returns
 * an empty list, the alert is still raised, the console still has it, the guards are
 * still pushed and the escalation ladder still runs — and the platform records that it
 * texted nobody, which is the truthful answer when it cannot find anybody to text.
 */
final class SiteEmergencyNumbers
{
    /**
     * The roles this platform knows, in the order a panic texts them.
     *
     * A role is what somebody IS to the site, so a message and a screen can name the
     * office rather than the person, and so a new president is one edited row rather
     * than a search through the platform for a phone number.
     *
     * @var array<string, string>
     */
    public const ROLES = [
        'president' => 'President',
        'property_office' => 'Property Office',
        'head_of_security' => 'Head of Security',
        'dispatch' => 'Gemini Dispatch',
    ];

    /**
     * A single site is texted at most this many numbers for one alert.
     *
     * An escalation, not a broadcast — the same cap and the same reasoning as
     * `SiteEscalation::MAX_CONTACTS`. Four today.
     */
    public const MAX_RECIPIENTS = 10;

    /**
     * Every active number for this estate, in the estate's own order.
     *
     * Rows with no diallable form are KEPT, not filtered out. The sender records them
     * as `unroutable`, which is how an office finds out that somebody typed a number
     * wrong — a silently dropped row is a number nobody ever discovers is dead.
     *
     * @return Collection<int, SiteEmergencyNumber>
     */
    public function forSite(string $tenantId): Collection
    {
        if ($tenantId === '') {
            return new Collection;
        }

        try {
            return SiteEmergencyNumber::query()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->orderBy('sort')
                ->orderBy('id')
                ->limit(self::MAX_RECIPIENTS)
                ->get();
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);

            return new Collection;
        }
    }

    /**
     * `forSite()` for several estates, in ONE query (M11 A2).
     *
     * The Gemini console's panic pop-up names each outstanding alert's Dispatch line, and
     * it is mounted on EVERY console page: asked one estate at a time it cost a query per
     * estate with an open alert, on every page anybody at Gemini opened. The answer per
     * estate is exactly `forSite()`'s — its active numbers, in `sort` then `id` order, at
     * most `MAX_RECIPIENTS` of them — and every estate asked for is a key, with an empty
     * list where it has none.
     *
     * NOTHING HERE THROWS either, for `forSite()`'s reason: a failed lookup answers with
     * empty lists, which is the truthful "nobody to call" rather than a broken page.
     *
     * @param  list<string>  $tenantIds
     * @return array<string, Collection<int, SiteEmergencyNumber>>
     */
    public function forSites(array $tenantIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('strval', $tenantIds),
            static fn (string $id): bool => $id !== '',
        )));

        $out = [];

        foreach ($ids as $id) {
            $out[$id] = new Collection;
        }

        if ($ids === []) {
            return $out;
        }

        try {
            $rows = SiteEmergencyNumber::query()
                ->whereIn('tenant_id', $ids)
                ->where('is_active', true)
                ->orderBy('tenant_id')
                ->orderBy('sort')
                ->orderBy('id')
                ->get();
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);

            return $out;
        }

        foreach ($rows->groupBy('tenant_id') as $tenantId => $numbers) {
            $out[(string) $tenantId] = $numbers->take(self::MAX_RECIPIENTS)->values();
        }

        return $out;
    }

    /**
     * Write a number down for a site, or correct one already there.
     *
     * The stored `number` is EXACTLY what was given. `e164` is derived beside it and is
     * refreshed on every write, so a row corrected from an unroutable number becomes
     * routable in the same act.
     *
     * ONE PHONE, ONE ROW THAT IS TEXTED (bug hunt B101). A row was found by the typed
     * string alone, so "876 508 8082" given for a President on file as "1-876-508-8082"
     * made a second active row for the same phone: two texts per panic, and a count one
     * too high. Now, when the phone is already on the list and texted:
     *
     *   the same role    it is the same entry, typed another way — that row is
     *                    corrected (and if the new spelling is itself a retired row, that
     *                    row comes back and the other is retired: one is texted, never two)
     *   another role     refused, naming the row it would collide with. One phone is one
     *                    text per panic, and which office it is filed under is the
     *                    office's decision to make on that row, not a silent overwrite.
     *
     * The database holds the same line (one active row per phone per site), so this is
     * never the only thing standing between a phone and a second text.
     *
     * `$sort` is the order the site is texted in. Null keeps a corrected row where it
     * already stands and puts a new one at the end — so correcting a spelling never
     * quietly moves the President behind Gemini Dispatch.
     *
     * @throws InvalidArgumentException when the phone is already on the list, texted, under another role
     */
    public function put(string $tenantId, string $role, string $label, string $number, ?int $sort = null, bool $active = true): SiteEmergencyNumber
    {
        $number = trim($number);
        $e164 = JamaicanNumber::e164($number);

        return SiteEmergencyNumber::query()->getConnection()->transaction(function () use ($tenantId, $role, $label, $number, $e164, $sort, $active): SiteEmergencyNumber {
            $exact = SiteEmergencyNumber::query()
                ->where('tenant_id', $tenantId)
                ->where('number', $number)
                ->lockForUpdate()
                ->first();

            // The same PHONE, on the list under another spelling and still texted.
            $samePhone = $e164 === null || ! $active ? null : SiteEmergencyNumber::query()
                ->where('tenant_id', $tenantId)
                ->where('e164', $e164)
                ->where('is_active', true)
                ->when($exact !== null, static fn ($query) => $query->whereKeyNot($exact?->getKey()))
                ->lockForUpdate()
                ->first();

            if ($samePhone !== null && (string) $samePhone->role !== $role) {
                throw new InvalidArgumentException(sprintf(
                    'That phone is already on this estate\'s list as %s (%s). A panic texts one phone once: correct that row with the number exactly as it is stored, or retire it first.',
                    $samePhone->label,
                    $samePhone->number,
                ));
            }

            $record = match (true) {
                $exact !== null => $exact,
                $samePhone !== null => $samePhone,
                default => new SiteEmergencyNumber,
            };

            // Both spellings are on file: the one given now is the one texted.
            if ($samePhone !== null && $exact !== null) {
                $samePhone->forceFill(['is_active' => false])->save();
            }

            $sort ??= $record->exists
                ? (int) $record->sort
                : (int) SiteEmergencyNumber::query()->where('tenant_id', $tenantId)->max('sort') + 1;

            $record->fill([
                'tenant_id' => $tenantId,
                'role' => $role,
                'label' => $label,
                'number' => $number,
                'e164' => $e164,
                'sort' => $sort,
                'is_active' => $active,
            ])->save();

            return $record;
        });
    }
}
