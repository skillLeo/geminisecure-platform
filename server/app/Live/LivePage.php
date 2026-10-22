<?php

declare(strict_types=1);

namespace App\Live;

use App\Http\Middleware\CaptureLiveHeads;
use App\Live\Auth\ChannelRegistry;
use App\Live\Auth\Principal;
use App\Models\User;
use InvalidArgumentException;

/**
 * A page's `live` prop (DESIGN.md §6.1, §6.4): the channels THIS viewer holds for it, the heads its
 * data was read at, and what the indicator needs to say about it.
 *
 *     'live' => LivePage::for($request->user(), feeds: [Channels::estateFeed($t, 'facilities')],
 *                             records: [Channels::estateRecord($t, 'facilities', 'ticket', $n)],
 *                             bindings: ['rows', 'kpis']),
 *
 *   {"channels": [{"name": "estate.pp.facilities", "streams": ["c","e"]}, …],
 *    "heads": {"c": 1042, "e": 88}, "bindings": ["rows","kpis"], "kind": "data"}
 *
 * THE CLIENT NEVER BUILDS A CHANNEL NAME, and the list is never wider than the viewer: every name the
 * page offers is decided by the same registry the socket's grants use, and one this viewer may not
 * hold is simply not in the list (so the page never subscribes to a refusal).
 *
 * `kind` is `data` (a screen that shows records), `form` (a create page, which shows nothing to go
 * stale) or `snapshot` (a report, with its own "as of"). A `data` page with no bindings says
 * permanently that it does not update by itself: until its wiring unit lands, that is the truth.
 */
final class LivePage
{
    public const KINDS = ['data', 'form', 'snapshot'];

    /**
     * @param  list<string>  $feeds  feed channel names, built by App\Live\Channels
     * @param  list<string>  $records  record channel names for the one record this page shows
     * @param  list<string>  $bindings  the page's live bindings, for the indicator
     * @return array{channels: list<array{name: string, streams: list<string>}>, heads: array<string, int>, bindings: list<string>, kind: string}
     */
    public static function for(User $viewer, array $feeds = [], array $records = [], array $bindings = [], string $kind = 'data'): array
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("A live page is data, form or snapshot, not '{$kind}'.");
        }

        /*
         * WITH THE SHELL'S PART (W0). The page's `live` prop replaces the shared one of the same name, so it
         * carries the shell's channels, principal and build too, and the shell never loses them on a wired page.
         */
        return [
            ...self::shell($viewer),
            'channels' => self::held(Principal::forRequest($viewer, request()), [...$feeds, ...$records]),
            'heads' => CaptureLiveHeads::forRequest(),
            'bindings' => array_values(array_unique($bindings)),
            'kind' => $kind,
        ];
    }

    /**
     * What every console page shares until its own screen says more (HandleInertiaRequests): the shell's
     * channels, and no bindings — so the page says "This screen does not update by itself" (§6.4).
     *
     * @return array<string, mixed>
     */
    public static function shellOnly(User $viewer): array
    {
        return [
            ...self::shell($viewer),
            'channels' => [],
            'bindings' => [],
            'kind' => 'data',
        ];
    }

    /**
     * The shell's part, worked out once per request however many times a page asks.
     *
     * @return array{shell: list<array{name: string, streams: list<string>}>, heads: array<string, int>, principal: string, build: string|null}
     */
    private static function shell(User $viewer): array
    {
        $request = request();
        $key = 'live.shell.'.$viewer->getKey();
        $shell = $request->attributes->get($key);

        if (! is_array($shell)) {
            $shell = LiveShell::for($viewer, $request);
            $request->attributes->set($key, $shell);
        }

        /** @var array{shell: list<array{name: string, streams: list<string>}>, heads: array<string, int>, principal: string, build: string|null} $shell */
        return $shell;
    }

    /**
     * The names this principal holds, each with the streams its cursor follows, in the order offered.
     *
     * @param  list<string>  $names
     * @return list<array{name: string, streams: list<string>}>
     */
    public static function held(Principal $principal, array $names): array
    {
        $names = array_values(array_unique($names));

        if ($names === []) {
            return [];
        }

        $allowed = app(ChannelRegistry::class)->decideMany($principal, $names);
        $held = [];

        foreach ($names as $name) {
            $channel = ($allowed[$name] ?? false) ? ChannelName::parse($name) : null;

            if ($channel !== null) {
                $held[] = ['name' => $name, 'streams' => $channel->streams()];
            }
        }

        return $held;
    }
}
