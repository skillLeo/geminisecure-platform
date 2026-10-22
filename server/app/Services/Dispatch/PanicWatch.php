<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Enums\AccessScope;
use App\Models\DuressAlert;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Platform\OperatorEstates;
use App\Support\SiteTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * WHAT THE GEMINI CONSOLE'S PANIC POP-UP IS TOLD, AND WHO IT IS TOLD TO (M10 §1.2 items 3 and 4).
 *
 * "The Gemini Director — dashboard alert." "The Gemini Head of Security for that site —
 * dashboard alert plus email." Nothing reached either of them live: the Gemini dashboard
 * had no subscription at all, so a Director learnt of a panic from the bell at their next
 * page load, or from a dispatch screen that happened to be open. The pop-up that fixes that
 * lives on the console's shell (`GeminiConsole.vue`, `useConsolePanic`); this is its server
 * half — the estates it listens to, what is still outstanding, and the one poll it makes.
 *
 * WHO IS WATCHED: WHOEVER MAY ACT ON AN ALERT. `gemini.dispatch.update` — acknowledge it,
 * send an officer, close it — plus `view`, which the alert screen the pop-up links to is
 * gated on. The matrix gives both to the Director, the Operations Manager, the Head of
 * Security (on their own sites) and the Dispatcher, and neither to the Accountant. The
 * ADMIN ASSISTANT holds `view` alone: they may watch the queue, so the live channel
 * admits them for it, but they may not answer a panic, and a pop-up interrupting somebody
 * who can do nothing about it teaches everybody to close pop-ups. A permission rather
 * than a list of role names, so a role the Director builds (M10 §4) inherits it from its
 * cells.
 *
 * WHICH ESTATES: EXACTLY THE ONES THE CHANNEL WILL ADMIT. `canAccessEstate()` is what
 * `routes/channels.php` asks, so this asks the same question the same way — every estate
 * for a role scoped to all of them, the assignment rows for anybody else — and leaves the
 * demonstration estate out, as the channel does (M5 §3). A list wider than the channel
 * would be refused subscriptions; a narrower one would be silence.
 *
 * WHAT IT CARRIES is what the broadcast already carries — the lot, the name, the kind, the
 * time, the officer count — plus the estate's NAME, which the broadcast does not have, and
 * the alert's status, which the pop-up needs so that it never claims an acknowledgement
 * that has not happened. No position, no medical detail, no money: the alert screen holds
 * those behind its own permission.
 *
 * AND THE ESTATE'S OWN CONSOLE (M10 §1.2 item 6; B039, B103). `forEstate()` and `pollEstate()`
 * are the same answer for one estate's committee — the five roles holding `estate.security.view`
 * — on their own console, so the estate's blinking bar outlives a page change and stops when the
 * control room acknowledges, exactly as the Director's does. One estate, never one the browser
 * names, and no link into this console.
 *
 * NEVER A SIMULATED ALERT. The platform's rule is that a simulated alert is sent to nobody
 * (`PanicFanOut`, `DeliveryReport`), and the Director's pop-up is one of §1.2's destinations.
 * It also keeps a simulator run from leaving the bar blinking on every page for ever.
 */
final class PanicWatch
{
    /** The newest outstanding alerts one answer carries. More than this is a control room, not a pop-up. */
    public const LIMIT = 20;

    /** The most alert ids one poll may ask about — the ones this browser is still showing. */
    public const MAX_ASKED = 50;

    /** Where the pop-up asks what has changed while an alert is outstanding or its socket is down. */
    public const POLL_URL = '/dispatch/panic-watch';

    /**
     * The estate console's own: a path under the estate's root, which the shell prefixes — the
     * estate's own hostname in production, `/estate/{key}` locally.
     */
    public const ESTATE_POLL_PATH = '/security/panic-watch';

    /** The statuses that close an alert — `DispatchController::SETTLED`. */
    public const SETTLED = ['resolved', 'false_alarm'];

    /**
     * WHAT "NOBODY HAS ACKNOWLEDGED IT" MEANS, IN ONE PLACE (B090).
     *
     * Not `status = 'open'`. An officer the panic reached may tap "On my way" before anybody in
     * the control room has looked, and `AlertProgress` then makes the alert `responding` with
     * `acknowledged_at` still null — `AlertIntake::acknowledge()` records the control room's
     * acknowledgement afterwards without stepping the status back. Counting only `open` dropped
     * that alert from the Director's outstanding list, so the bar stopped (D-148: "only when the
     * server reports the alert acknowledged or resolved") and the card said "Acknowledged" about
     * an alert nobody in the control room had acknowledged. An alert is outstanding until the
     * control room acknowledges it or it is closed; `alertStanding.js` is the same rule in the
     * browser.
     *
     * @param  Builder<DuressAlert>  $query
     * @return Builder<DuressAlert>
     */
    public static function awaitingControlRoom(Builder $query): Builder
    {
        return $query->whereNull('acknowledged_at')->whereNotIn('status', self::SETTLED);
    }

    /** @var array<string, array{label: string, number: string}|null> */
    private array $calls = [];

    public function __construct(private readonly SiteEmergencyNumbers $numbers) {}

    /**
     * Whether a panic interrupts this person on every Gemini console screen.
     *
     * Gemini staff only: an estate account is told by its own console's pop-up, on its own
     * estate's channel, and never through this.
     */
    public static function watches(?User $viewer): bool
    {
        return $viewer !== null
            && $viewer->isGeminiStaff()
            && $viewer->status === 'active'
            && $viewer->can('gemini.dispatch.view')
            && $viewer->can('gemini.dispatch.update');
    }

    /**
     * The shared prop the console's shell mounts the pop-up on — null for anybody not watched.
     *
     * The alerts here light the blinking bar and nothing more: they were outstanding before
     * this page was drawn, so they are not news, and re-opening a pop-up on every page load
     * for an alert already read would teach a Director to close it without looking.
     *
     * @return array{estates: list<array{id: string, name: string}>, alerts: list<array<string, mixed>>, poll: string, checked_at: string}|null
     */
    public function forViewer(User $viewer): ?array
    {
        if (! self::watches($viewer)) {
            return null;
        }

        $estates = $this->estates($viewer);

        return [
            'estates' => $estates,
            'alerts' => $this->shape($this->outstanding(array_column($estates, 'id'))),
            'poll' => self::POLL_URL,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * What the pop-up polls for: every alert still outstanding, and the current state of the
     * ones it is already showing — so the bar stops once an alert is acknowledged, and an
     * alert raised while the socket was down still reaches the screen.
     *
     * AN ASKED-FOR ALERT OUTSIDE THIS VIEWER'S SCOPE IS SIMPLY ABSENT. The browser names the
     * ids; the server decides which of them it may answer about, from the same estate list
     * the channel uses. An absence tells the pop-up to forget the alert, which is also what
     * happens when a Head of Security is taken off a site mid-shift.
     *
     * @param  list<int>  $asked
     * @return array{alerts: list<array<string, mixed>>, checked_at: string}|null
     */
    public function poll(User $viewer, array $asked): ?array
    {
        if (! self::watches($viewer)) {
            return null;
        }

        $estateIds = array_column($this->estates($viewer), 'id');
        $alerts = $this->outstanding($estateIds);

        $asked = array_values(array_unique(array_filter($asked, static fn (int $id): bool => $id > 0)));

        if ($asked !== [] && $estateIds !== []) {
            $known = DuressAlert::query()
                ->with(['estate', 'raisedByGuard'])
                ->whereKey(array_slice($asked, 0, self::MAX_ASKED))
                ->whereIn('tenant_id', $estateIds)
                ->where('is_simulated', false)
                ->get();

            $alerts = $alerts->merge($known)->unique('id')->values();
        }

        return [
            'alerts' => $this->shape($alerts),
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * THE ESTATE CONSOLE'S WATCH — the `panicWatch` shared prop (M10 §1.2 item 6; B039, B103).
     *
     * "A persistent blinking indicator until the alert is acknowledged." The estate's shell is
     * built afresh by every page, and its pop-up knew only what the socket had told it since the
     * page loaded — so any link cleared the bar with nobody having acknowledged anything, and a
     * committee member who opened the console after the panic saw nothing. This is what every
     * estate page now carries: the alerts at THIS estate nobody in the control room has
     * acknowledged (`awaitingControlRoom`), which light the bar without re-opening the pop-up,
     * and the path the pop-up asks while any is outstanding or its channel is down.
     *
     * Who gets it is decided where it is shared — `estate.security.view`, the five roles §1.2
     * names — and the estate is the one whose console is open, never one the browser names.
     *
     * @return array{estate: string, alerts: list<array<string, mixed>>, poll: string, checked_at: string}
     */
    public function forEstate(string $estateId): array
    {
        return [
            'estate' => $estateId,
            'zone' => SiteTime::zone($estateId),
            'alerts' => $this->shapeForEstate($estateId, $this->outstanding([$estateId])),
            'poll' => self::ESTATE_POLL_PATH,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * What the estate's pop-up polls for: everything outstanding at this estate, and where the
     * alerts it is showing now stand — so the bar stops once the control room acknowledges, and
     * an alert raised while the channel was down still reaches the screen.
     *
     * THE ASKED-FOR IDS ARE ANSWERED ONLY AT THIS ESTATE. An id from anywhere else is simply
     * absent, and the pop-up forgets it. A SIMULATED alert of this estate is answered when asked
     * about, although it is never on the outstanding list: the estate's pop-up shows one it was
     * sent live, labelled SIMULATED, and has to be told when it is closed.
     *
     * @param  list<int>  $asked
     * @return array{alerts: list<array<string, mixed>>, checked_at: string}
     */
    public function pollEstate(string $estateId, array $asked): array
    {
        $alerts = $this->outstanding([$estateId]);

        $asked = array_values(array_unique(array_filter($asked, static fn (int $id): bool => $id > 0)));

        if ($asked !== []) {
            $known = DuressAlert::query()
                ->with(['estate', 'raisedByGuard'])
                ->whereKey(array_slice($asked, 0, self::MAX_ASKED))
                ->where('tenant_id', $estateId)
                ->get();

            $alerts = $alerts->merge($known)->unique('id')->values();
        }

        return [
            'alerts' => $this->shapeForEstate($estateId, $alerts),
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * OF THE ALERTS A DISPATCH SCREEN'S SIREN IS SOUNDING FOR, THE ONES STILL AWAITING THE CONTROL
     * ROOM (B107) — so the siren stops once the last of them is acknowledged, in whichever
     * console that happened. An acknowledgement is never broadcast; the screen that is sounding
     * asks, every few seconds, while it sounds.
     *
     * Any dispatch viewer may ask, because every dispatch screen sounds the siren — the Admin
     * Assistant's queue included — and it is answered only about alerts at estates this viewer
     * may open (`canAccessEstate`, the alert screen's own test). Anything else is simply absent,
     * which stops the siren for it: an alert this viewer may not see is not theirs to hear.
     * Simulated alerts are answered: the siren sounds for them too.
     *
     * @param  list<int>  $asked
     * @return list<int>
     */
    public function stillAwaiting(User $viewer, array $asked): array
    {
        $asked = array_values(array_unique(array_filter($asked, static fn (int $id): bool => $id > 0)));

        if ($asked === []) {
            return [];
        }

        $alerts = self::awaitingControlRoom(DuressAlert::query())
            ->whereKey(array_slice($asked, 0, self::MAX_ASKED))
            ->get(['id', 'tenant_id']);

        // Every estate these alerts are at, asked of the viewer in one read (M11 A2) — it was a
        // read per alert, up to fifty, every few seconds for as long as the siren sounded.
        $reachable = $viewer->canAccessEstates($alerts->map(static fn (DuressAlert $alert): string => (string) $alert->tenant_id)->all());

        return $alerts
            ->filter(static fn (DuressAlert $alert): bool => $reachable[(string) $alert->tenant_id] ?? false)
            ->map(static fn (DuressAlert $alert): int => (int) $alert->id)
            ->values()
            ->all();
    }

    /**
     * The estates whose channel this viewer may subscribe to, with the names the pop-up shows.
     *
     * @return list<array{id: string, name: string}>
     */
    public function estates(User $viewer): array
    {
        /*
         * `OperatorEstates::estates()` rather than `Tenant::query()->get()`: it leaves the
         * demonstration estate out exactly as the channel does, and it is typed as a collection
         * of Tenants — stancl's own TenantCollection is not, so a closure over `Tenant` did not
         * type-check against it.
         */
        $estates = OperatorEstates::estates(static function (Builder $query) use ($viewer): void {
            // `canAccessEstate()`'s own rule: "all" reaches every estate; anything narrower
            // reaches the assignment rows, and none at all when there are none.
            if ($viewer->widestScope() !== AccessScope::All) {
                $query->whereIn('tenants.id', $viewer->accessibleEstateIds());
            }

            $query->orderBy('name');
        });

        return $estates
            ->map(static fn (Tenant $estate): array => [
                'id' => (string) $estate->getTenantKey(),
                'name' => (string) ($estate->name ?? $estate->getTenantKey()),
                // The pop-up says when it happened AT THE SITE, tagged, beside how long ago (M19A §7). Off the row
                // already read, so the shell asks no query more for it on any page.
                'zone' => SiteTime::zoneOf($estate),
            ])
            ->values()
            ->all();
    }

    /**
     * Alerts nobody in the control room has acknowledged yet, at these estates, newest first —
     * an officer already on the way from the handset included (`awaitingControlRoom`).
     *
     * @param  list<string>  $estateIds
     * @return Collection<int, DuressAlert>
     */
    private function outstanding(array $estateIds): Collection
    {
        if ($estateIds === []) {
            return new Collection;
        }

        return self::awaitingControlRoom(DuressAlert::query())
            ->with(['estate', 'raisedByGuard'])
            ->whereIn('tenant_id', $estateIds)
            ->where('is_simulated', false)
            ->orderByDesc('server_time')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * @param  Collection<int, DuressAlert>  $alerts
     * @return list<array<string, mixed>>
     */
    private function shape(Collection $alerts): array
    {
        // Every estate's Dispatch line in one query, before the rows ask for them (M11 A2).
        $this->prefetchCalls($alerts->map(static fn (DuressAlert $alert): string => (string) $alert->tenant_id)->all());

        // `server_time` is never null: the column is NOT NULL, stamped by the database on insert.
        return $alerts->sortByDesc(static fn (DuressAlert $alert): int => $alert->server_time->getTimestamp())
            ->map(fn (DuressAlert $alert): array => [
                'id' => (int) $alert->id,
                'estate' => (string) $alert->tenant_id,
                'estate_name' => (string) ($alert->estate->name ?? $alert->tenant_id),
                'kind' => (string) $alert->kind,
                'kind_label' => $alert->kindLabel(),
                // The broadcast's own rule (`AlertRaised`), so the pop-up and the siren agree: drill mode sounds nothing.
                'is_urgent' => $alert->isPanic() && ! $alert->wakesNobody(),
                'is_drill' => (bool) $alert->is_drill,
                'drill_mode' => $alert->wakesNobody(),
                'is_simulated' => (bool) $alert->is_simulated,
                'server_time' => $alert->server_time->toIso8601String(),

                // The lot and the person, as the broadcast carries them — the guard's own
                // name for a duress, where the handset sends none.
                'unit' => $alert->unit_reference,
                'who' => $alert->raisedByGuard->full_name ?? $alert->raised_by_name,

                // The fan-out's own count, passed through — null while it is not known.
                'alerted_handsets' => $alert->getAttribute('alerted_handsets') === null ? null : (int) $alert->getAttribute('alerted_handsets'),
                'alerted_via' => $alert->getAttribute('alerted_via'),
                'call' => $this->callFor((string) $alert->tenant_id),

                /*
                 * THE STATUS, SO THE POP-UP NEVER CLAIMS WHAT HAS NOT HAPPENED — and the
                 * response's own steps (`AlertProgress`), which live in columns rather than
                 * in `status`: "responding" alone cannot say whether an officer is on the
                 * way or already at the lot.
                 */
                'status' => (string) $alert->status,
                'acknowledged_at' => $alert->acknowledged_at?->toIso8601String(),
                'dispatched_at' => $alert->getAttribute('dispatched_at')?->toIso8601String(),
                'en_route_at' => $alert->getAttribute('en_route_at')?->toIso8601String(),
                'on_scene_at' => $alert->getAttribute('on_scene_at')?->toIso8601String(),
                'resolved_at' => $alert->resolved_at?->toIso8601String(),

                // A raiser's own cancel is closed as a false alarm; the card says which it was.
                'cancelled' => $alert->getAttribute('cancelled_at') !== null,

                'href' => '/dispatch/alerts/'.$alert->id,
            ])
            ->values()
            ->all();
    }

    /**
     * The same rows, for the estate's own pop-up: without the link into Gemini's console, which an
     * estate account cannot open, and with whether Gemini's control room receives this estate's
     * alerts at all — the fact the pop-up says instead of a reassurance, as `AlertRaised` sends it.
     *
     * @param  Collection<int, DuressAlert>  $alerts
     * @return list<array<string, mixed>>
     */
    private function shapeForEstate(string $estateId, Collection $alerts): array
    {
        $controlRoom = rescue(static fn (): bool => ! OperatorEstates::isDemonstration($estateId), null, false);

        return array_map(static function (array $row) use ($controlRoom): array {
            unset($row['href']);

            return [...$row, 'control_room' => $controlRoom];
        }, $this->shape($alerts));
    }

    /**
     * The site's own Gemini Dispatch line, exactly as `AlertRaised` sends it — read once per
     * estate per request, however many of its alerts are in the answer.
     *
     * @return array{label: string, number: string}|null
     */
    private function callFor(string $tenantId): ?array
    {
        if (! array_key_exists($tenantId, $this->calls)) {
            $this->calls[$tenantId] = rescue(function () use ($tenantId): ?array {
                $dispatch = $this->numbers->forSite($tenantId)->firstWhere('role', 'dispatch');

                return $dispatch === null ? null : ['label' => (string) $dispatch->label, 'number' => (string) $dispatch->number];
            }, null, false);
        }

        return $this->calls[$tenantId];
    }

    /**
     * `callFor()`'s answer for every one of these estates, from ONE read (M11 A2).
     *
     * THIS IS ON EVERY GEMINI PAGE. The pop-up's shared prop is drawn for each page a
     * Director or dispatcher opens, and asking `site_emergency_numbers` one estate at a time
     * was a query per estate with an open alert on every one of those pages — a cross-estate
     * loop with a query each. `SiteEmergencyNumbers::forSites()` answers them together with
     * the same per-estate list `forSite()` gives, so each estate's line is exactly the one
     * `callFor()` would have found, and a failure still reads as no line rather than a page.
     *
     * @param  list<string>  $tenantIds
     */
    private function prefetchCalls(array $tenantIds): void
    {
        $missing = array_values(array_filter(
            array_unique($tenantIds),
            fn (string $id): bool => ! array_key_exists($id, $this->calls),
        ));

        if ($missing === []) {
            return;
        }

        $numbers = rescue(fn (): array => $this->numbers->forSites($missing), [], false);

        foreach ($missing as $tenantId) {
            $this->calls[$tenantId] = rescue(static function () use ($numbers, $tenantId): ?array {
                $dispatch = ($numbers[$tenantId] ?? new Collection)->firstWhere('role', 'dispatch');

                return $dispatch === null ? null : ['label' => (string) $dispatch->label, 'number' => (string) $dispatch->number];
            }, null, false);
        }
    }
}
