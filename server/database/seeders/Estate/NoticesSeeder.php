<?php

declare(strict_types=1);

namespace Database\Seeders\Estate;

use App\Models\Estate\Notice;
use App\Models\Estate\Resident;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Board 32's three notices, and enough read receipts to reproduce its bars.
 *
 * THE SEEN PERCENTAGES ARE NOT SEEDED, THE RECEIPTS ARE. Board 32 draws 47%,
 * 71% and 88%, and `Notices::board()` computes each as receipts over the size of
 * the audience. So this seeder writes the RECEIPTS — the right number of
 * residents having read each notice — and the bars come out on their own. Storing
 * the percentage would make the screen agree with the board while proving
 * nothing about the arithmetic behind it, which is the whole reason that figure
 * is on the screen.
 *
 * The counts are therefore derived from the audience rather than fixed: 47% of
 * whatever Phase 2's roll is today, not a hardcoded 212. An estate that grows
 * still draws 47%.
 *
 * TWO BYLINES, TWO SHAPES, AND THE BOARD IS RIGHT ABOUT BOTH. Its first notice
 * reads "Posted by Property Manager" and its other two name people. A gate
 * closure belongs to the OFFICE — whoever manages the estate next month owns it
 * too — while an AGM notice is signed by the Secretary personally. The author is
 * recorded on all three either way; `posted_as_role` only changes what is shown.
 *
 * DATES ARE OFFSETS FROM TODAY, not the board's literals. Board 32 posts its
 * newest notice on "Sep 4, 8:15 AM" and this estate is seeded whenever the
 * command runs; a notice fixed to a date in September 2026 would read as months
 * old by the time anybody opened the screen, and the list sorts newest first.
 *
 * The offsets are the BOARD'S OWN — six, nine and twelve days before the day it
 * was drawn — so an estate seeded that day reproduces Sep 4, Sep 1 and Aug 29
 * exactly, and one seeded later keeps the same three-day spacing running back
 * from whenever it was.
 */
class NoticesSeeder extends Seeder
{
    /**
     * The three notices, verbatim, newest first.
     *
     * `days` and `at` place each one relative to today; `seen` is the board's
     * own percentage, which this seeder turns into that many receipts.
     *
     * @var list<array<string, mixed>>
     */
    private const NOTICES = [
        [
            'kind' => Notice::URGENT,
            'title' => 'Gate 2 temporarily closed for repairs',
            'body' => 'Gate 2 is closed while our contractor repairs the barrier arm sensor. '.
                'Use the Main Gate until further notice.',
            'author' => 'Patricia Morgan',

            // The office, not the person. See the class docblock.
            'as_role' => 'Property Manager',
            'scope' => Notice::ESTATE_WIDE,
            'phase' => null,
            'days' => 6,
            'at' => '08:15',
            'seen' => 47,
        ],
        [
            'kind' => Notice::GENERAL,
            // No em dash in what a person reads, and the day first (Law 3, Law 5; batch 1 item 6). `was` is its old title.
            'title' => 'Annual General Meeting, 27 Sep',
            'was' => 'Annual General Meeting — Sep 27',
            'body' => 'AGM 2026 is scheduled for Saturday 27 September, 10:00 AM. Agenda attached.',
            'author' => 'Delroy Samuels',
            'as_role' => null,
            'scope' => Notice::ESTATE_WIDE,
            'phase' => null,
            'days' => 9,
            'at' => '09:00',
            'seen' => 71,
        ],
        [
            'kind' => Notice::GENERAL,
            'title' => 'Pool deck maintenance, 8 to 9 Sep',
            'was' => 'Pool deck maintenance — Sep 8-9',
            'body' => 'The pool deck will be closed for filter maintenance over the weekend.',
            'author' => 'Patricia Morgan',
            'as_role' => null,
            'scope' => Notice::ESTATE_WIDE,
            'phase' => null,
            'days' => 12,
            'at' => '14:30',
            'seen' => 88,
        ],
    ];

    public function run(): void
    {
        /*
         * The audience, once. All three of board 32's notices are estate-wide,
         * so the denominator is the whole roll — and it is read rather than
         * assumed, because the seen percentages are computed against it and a
         * seeder that guessed 450 would draw the wrong bars on an estate of 433.
         */
        $residents = Resident::query()->orderBy('id')->pluck('id')->all();

        if ($residents === []) {
            // No households, so no audience and no receipts to write. The
            // notices themselves would draw 0% against an empty estate, which
            // is honest but not what board 32 shows, so nothing is seeded.
            return;
        }

        foreach (self::NOTICES as $row) {
            $postedAt = Carbon::today()
                ->subDays((int) $row['days'])
                ->setTimeFromTimeString($row['at']);

            // A notice seeded under its old title is renamed, never seeded a second time beside it.
            if (isset($row['was'])) {
                Notice::query()->where('title', $row['was'])->update(['title' => $row['title']]);
            }

            $notice = Notice::updateOrCreate(
                ['title' => $row['title']],
                [
                    'kind' => $row['kind'],
                    'body' => $row['body'],
                    'audience_scope' => $row['scope'],
                    'audience_phase' => $row['phase'],
                    'author_id' => null,
                    'author_name' => $row['author'],
                    'posted_as_role' => $row['as_role'],
                    'published_at' => $postedAt,
                ],
            );

            $this->seedReceipts($notice, $residents, (int) $row['seen'], $postedAt);
        }
    }

    /**
     * Enough receipts to make this notice's bar read what the board draws.
     *
     * TAKEN FROM THE FRONT OF THE ROLL, DELIBERATELY. Which particular residents
     * have read a notice is not a fact any board states and not one this seeder
     * is entitled to invent a pattern for — so it is the first N by id, which is
     * arbitrary and visibly so, rather than a random sample that would make the
     * fixture different on every run and the bars impossible to check.
     *
     * @param  list<int>  $residents
     */
    private function seedReceipts(Notice $notice, array $residents, int $percent, Carbon $postedAt): void
    {
        $target = (int) round(count($residents) * $percent / 100);

        if ($notice->reads()->count() >= $target) {
            // Already seeded. Adding more would push the bar past the board's
            // figure on every subsequent run.
            return;
        }

        $rows = [];

        foreach (array_slice($residents, 0, $target) as $index => $residentId) {
            $rows[] = [
                'notice_id' => $notice->id,
                'resident_id' => $residentId,

                /*
                 * Spread across the hours after posting rather than stamped all
                 * at the same instant. "Half the estate had seen it by
                 * Wednesday" is the question the timestamp exists to answer, and
                 * four hundred receipts sharing one second cannot answer it.
                 */
                'read_at' => $postedAt->copy()->addMinutes($index % 2_880),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::connection('tenant')->table('notice_reads')->insertOrIgnore($chunk);
        }
    }
}
