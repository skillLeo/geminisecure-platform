<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The six states every screen has to be able to show, forced on demand.
 *
 * A screen is not finished when its happy path renders. It is finished when
 * all six of these do, and the other five are the ones that are never reviewed
 * — because reaching them means contriving the data. An empty filtered list
 * needs a search that matches nothing; a permission-denied panel needs a
 * second account; a loading skeleton lasts 80ms on a local machine and cannot
 * be looked at at all.
 *
 * So in local development `?_state=` forces one:
 *
 *     /clients?_state=loading
 *     /clients?_state=empty-filtered
 *     /guards?_state=denied
 *
 * LOCAL ONLY, and asserted twice — here and in the middleware that shares it.
 * A query parameter that can make a screen claim it has no data, or that a
 * viewer lacks permission, is a query parameter that can be used to mislead
 * someone into thinking a record is gone. It must not exist in production.
 */
final class ScreenState
{
    /** Nothing has arrived yet. Draw a skeleton, never a spinner. */
    public const LOADING = 'loading';

    /** Genuinely nothing here yet — first use. Offer the action that creates one. */
    public const EMPTY_FIRST_USE = 'empty';

    /** Records exist, this filter matches none. Offer to clear the filter. */
    public const EMPTY_FILTERED = 'empty-filtered';

    /** The ordinary case. */
    public const POPULATED = 'populated';

    /** Something failed. Say what, and offer a way to retry. */
    public const ERROR = 'error';

    /** The viewer's role does not reach this. Name the role, not the rule. */
    public const DENIED = 'denied';

    /** @var list<string> */
    public const ALL = [
        self::LOADING,
        self::EMPTY_FIRST_USE,
        self::EMPTY_FILTERED,
        self::POPULATED,
        self::ERROR,
        self::DENIED,
    ];

    /**
     * The state this request is asking to be shown, or null for the real one.
     *
     * Matched against the fixed list rather than echoed: the value arrives in
     * a query string, and a query string is the visitor's to write.
     */
    public static function forced(Request $request): ?string
    {
        if (! app()->isLocal()) {
            return null;
        }

        $state = $request->query('_state');

        return is_string($state) && in_array($state, self::ALL, true) ? $state : null;
    }
}
