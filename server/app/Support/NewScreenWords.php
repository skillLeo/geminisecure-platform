<?php

declare(strict_types=1);

namespace App\Support;

/**
 * THE NEW SCREENS' WORDS FOR THE CLASSIC PAGES' REFUSALS (M30 §9, M31 B7).
 *
 * The Schedule, its shift panel and Places write through the classic pages' own services (`Roster`, `SiteGeography`),
 * so a refusal those services raise reaches the new screens in the classic pages' words. Those words stay exactly as
 * they are on the classic pages; the few sentences that say placed, geofence, fence, radius or duty are said again
 * here, in the words the new screens use (`Placed` is `Location set`, a geofence is the area a guard must be inside
 * to clock in, a radius is how close they must be). Every other sentence comes through as it is.
 */
final class NewScreenWords
{
    /**
     * A pattern over the classic sentence => the new screens' sentence; `$1` and `$2` are what the pattern caught.
     *
     * @var array<string, string>
     */
    public const SAID_HERE = [
        // Roster::checkOfficer, when a guard is suspended between the panel's check and the save.
        '/^(.+) is suspended from duty and cannot be posted\./' => '$1 is suspended, so cannot be put on a shift.',
        // SiteGeography, from a step of Places. (Its "Say why" never reaches Places: every write there gives a reason.)
        '/^The estate is placed with its centre and its radius together/' => 'The estate needs its position and how far out it reaches, both together.',
        '/^That is how this estate is placed already\./' => 'That is where the estate is already, so there is nothing to save.',
        '/^(.+) is a patrol route, and a patrol covers more ground than any circle\./' => '$1 is a patrol, and a patrol covers more ground than any one area, so it has no location of its own. Choose the gate it starts at, where its guard clocks in.',
        '/^A checkpoint is placed with both its latitude and its longitude/' => 'A checkpoint\'s location needs both numbers, its latitude and its longitude.',
        '/^A checkpoint has a name a guard can find/' => 'A checkpoint needs a name a guard can find, such as "Pool gate", of at most 120 characters.',
        '/^A checkpoint\'s radius is between (\d+) and (\d+) metres/' => 'How close a guard\'s phone must be to a checkpoint\'s tag for the scan to count is between $1 and $2 metres.',
        '/^A checkpoint is in the list twice\./' => 'A checkpoint is in the list twice. Each one has one location, where its tag hangs.',
        '/^A geofence radius is between ([\d,]+) and ([\d,]+) metres\./' => 'How close a guard must be to clock in is between $1 and $2 metres.',
    ];

    /** A classic page's refusal in the new screens' words, or as it came when it has none of the old ones. */
    public static function say(string $classic): string
    {
        // A refusal about one line of a list keeps its "Line 4: ", and what follows is said in the new words.
        if (preg_match('/^(Line \d+: )(.+)$/s', $classic, $line) === 1) {
            return $line[1].self::say($line[2]);
        }

        foreach (self::SAID_HERE as $pattern => $said) {
            if (preg_match($pattern, $classic, $caught) === 1) {
                return strtr($said, ['$1' => $caught[1] ?? '', '$2' => $caught[2] ?? '']);
            }
        }

        return $classic;
    }
}
