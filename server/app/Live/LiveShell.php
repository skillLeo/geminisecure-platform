<?php

declare(strict_types=1);

namespace App\Live;

use App\Enums\AccessScope;
use App\Http\Middleware\CaptureLiveHeads;
use App\Live\Auth\Principal;
use App\Models\User;
use App\Services\Dispatch\PanicWatch;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/**
 * The shell's `live` prop: the channels every page of a console holds (DESIGN.md §6.1).
 *
 *   {"shell": [{"name": "user.12", "streams": ["c"]}, …], "heads": {"c": 1042, "e": 88},
 *    "principal": "u-12", "build": "a342c56"}
 *
 * What a console shell holds, whatever page is open:
 *   - `user.{u}`, the person's own: their access changes, their bell's read marks;
 *   - THE PANIC FEED: on an estate's own host `estate.{t}.security`; on the Gemini console the
 *     dispatch feed of every estate the viewer watches, computed with the SAME predicate as the
 *     panic pop-up and the authoriser (PanicWatch::estates): the fan-in `gemini.dispatch` for a
 *     viewer who reaches every estate, the estates' own copies otherwise;
 *   - the channels that feed the bell (§2.17): on an estate host `estate.{t}.console`,
 *     `estate.{t}.residents` (claims) and `estate.{t}.facilities` (tickets past target, deposits);
 *     on the Gemini console the workforce, billing and payroll feeds, the same way as dispatch.
 * Every one is then decided by the registry for this viewer, so a feed the person may not hold is
 * simply not listed. W0 shares this as the `live` prop and mounts the indicator.
 */
final class LiveShell
{
    /*
     * EVERY FEED THE VIEWER MAY HOLD, ON EVERY PAGE (M11 §2, D-175). The shell held the bell's and the panic's feeds;
     * each page was to add its own through `LivePage::for`. It holds all of them now, each still decided by the
     * registry against the page it mirrors — so nothing reaches a viewer that viewer's page would not show — and a
     * page binds only the entities it draws (`useLiveReload`). One subscription set per console, the same on every
     * page, is also what lets catch-up keep one cursor per channel however the person moves around the console.
     */

    /** The Gemini feeds the Gemini shell holds on every page (§2.17, D-175). */
    public const GEMINI_FEEDS = ['dispatch', 'workforce', 'clients', 'billing', 'audit', 'settings', 'payroll', 'dashboard'];

    /** The estate feeds an estate console's shell holds on every page (D-175). */
    public const ESTATE_FEEDS = ['security', 'console', 'residents', 'facilities', 'dues', 'ledger', 'payments', 'accounting', 'governance', 'settings', 'billing', 'payroll', 'structure'];

    /**
     * @return array{shell: list<array{name: string, streams: list<string>}>, heads: array<string, int>, principal: string, build: string|null}
     */
    public static function for(User $user, ?Request $request = null): array
    {
        $request ??= request();
        $principal = Principal::forRequest($user, $request);

        $build = config('live.build');

        return [
            'shell' => LivePage::held($principal, self::candidates($user, $principal->hostTenant)),
            'heads' => CaptureLiveHeads::forRequest($request),
            'principal' => $principal->key(),
            'build' => is_string($build) && $build !== '' ? $build : null,
        ];
    }

    /**
     * Every channel the shell would hold, before the registry decides.
     *
     * @return list<string>
     */
    public static function candidates(User $user, ?string $hostTenant): array
    {
        $names = [Channels::user($user->id)];

        try {
            if ($hostTenant !== null) {
                foreach (self::ESTATE_FEEDS as $feed) {
                    $names[] = Channels::estateFeed($hostTenant, $feed);
                }

                return $names;
            }

            $everyEstate = $user->widestScope() === AccessScope::All;
            $estates = $everyEstate ? [] : array_map(static fn (array $estate): string => $estate['id'], app(PanicWatch::class)->estates($user));

            foreach (self::GEMINI_FEEDS as $feed) {
                if ($everyEstate) {
                    $names[] = Channels::geminiFanIn($feed);

                    continue;
                }

                // A platform-only feed has no per-estate copy: a viewer on assigned sites holds none of it.
                if (in_array($feed, Channels::GEMINI_PLATFORM_ONLY, true)) {
                    continue;
                }

                foreach ($estates as $estate) {
                    array_push($names, ...Channels::gemini($feed, $estate));
                }
            }
        } catch (InvalidArgumentException) {
            // A key the grammar cannot carry names no channel; the person's own is still theirs.
        } catch (Throwable $e) {
            report($e);
        }

        // gemini() lists the fan-in with each estate's copy; a scoped viewer holds only the copies.
        return array_values(array_unique(array_filter(
            $names,
            static fn (string $name): bool => $hostTenant !== null || $user->widestScope() === AccessScope::All || ! in_array($name, array_map(Channels::geminiFanIn(...), self::GEMINI_FEEDS), true),
        )));
    }
}
