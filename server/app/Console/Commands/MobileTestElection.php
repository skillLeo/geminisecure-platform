<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Estate\Ballot;
use App\Models\Estate\BallotOption;
use App\Models\Estate\BallotPosition;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\Tenant;
use App\Services\Estate\Governance;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 *   php artisan mobile:test-election
 *   php artisan mobile:test-election --close
 *
 * Opens a clearly-labelled TEST election on the test estate so the Resident App's
 * voting screens can be run end to end — mark, review, cast, receipt — against
 * a real open poll, instead of waiting for a real election to happen to be open.
 *
 * One household gets one paper, and a cast paper can never be taken back, so a
 * re-run cannot reuse the last test election: EVERY RUN OPENS A NEW ONE and first
 * closes any earlier test election still open (the ordinary `Governance::close`).
 * Nothing is ever deleted — receipts and marks are append-only, and a test
 * election is recognisable by its title ("Mobile build check election (TEST …)").
 * Its `code` is null: the column is the one-letter "A"/"B" of a real year's
 * ballots and is unique per year, so a test election must not take a letter.
 *
 * It goes through the SAME service the console uses (`Governance::open`), so the
 * poll the app sees is a poll the estate's own rules opened: eligibility, the
 * household count and the closing time are the real ones.
 *
 * It prints whether the test household is ELIGIBLE under the estate's own rules
 * and never changes a ledger to make it so — if the household is in arrears past
 * the estate's threshold, the app must show the refusal, and that is the test.
 *
 * LOCAL ONLY, like the other mobile:* commands.
 */
class MobileTestElection extends Command
{
    public const TITLE_PREFIX = 'Mobile build check election (TEST ';

    protected $signature = 'mobile:test-election
        {--close : only close the test elections that are still open; open nothing}
        {--hours=6 : how long the poll stays open}';

    protected $description = 'Open a labelled TEST election on the test estate so a real ballot can be cast from the Resident App (local only)';

    public function handle(Governance $governance): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Refused: a test election is opened on a developer\'s machine only (APP_ENV is '.app()->environment().').');

            return self::FAILURE;
        }

        $estate = Tenant::query()->find(MobileTestAccounts::ESTATE);

        if (! $estate instanceof Tenant) {
            $this->error('The estate ['.MobileTestAccounts::ESTATE.'] does not exist. Provision and seed the platform first.');

            return self::FAILURE;
        }

        $onlyClose = (bool) $this->option('close');
        $hours = max(1, (int) $this->option('hours'));

        /** @var list<string> $lines */
        $lines = $estate->run(function () use ($governance, $onlyClose, $hours): array {
            $lines = [];

            $stillOpen = Ballot::query()
                ->where('title', 'like', self::TITLE_PREFIX.'%')
                ->where('stage', Ballot::VOTING_OPEN)
                ->get();

            foreach ($stillOpen as $earlier) {
                $governance->close($earlier);
                $lines[] = 'closed   #'.$earlier->id.'  '.$earlier->title;
            }

            if ($onlyClose) {
                return $lines;
            }

            $stamp = Carbon::now()->format('Ymd-His');

            $ballot = Ballot::query()->create([
                'year' => (int) Carbon::now()->year,
                'code' => null,
                'title' => self::TITLE_PREFIX.$stamp.')',
                'kind' => Ballot::ELECTION,
                'description' => 'A test election opened by `php artisan mobile:test-election` so the Resident App\'s voting can be checked end to end. Not a real poll.',
                // The stage before the poll, so the ONE step left is the console's own "open voting".
                'stage' => Ballot::CAMPAIGN_PERIOD,
                'quorum_percent' => 1,
            ]);

            $seats = [
                'Test Chairperson' => ['Test Candidate A (not a real person)', 'Test Candidate B (not a real person)'],
                'Test Treasurer' => ['Test Candidate C (not a real person)', 'Test Candidate D (not a real person)'],
            ];

            $order = 0;

            foreach ($seats as $name => $candidates) {
                $position = BallotPosition::query()->create([
                    'ballot_id' => $ballot->id,
                    'name' => $name,
                    'seat_count' => 1,
                    'scope' => BallotPosition::ESTATE,
                    'sort_order' => ++$order,
                ]);

                foreach ($candidates as $i => $label) {
                    BallotOption::query()->create([
                        'ballot_id' => $ballot->id,
                        'ballot_position_id' => $position->id,
                        'label' => $label,
                        'sort_order' => $i + 1,
                    ]);
                }
            }

            // A moment, not digits: whatever zone the estate keeps, this is "N hours from now".
            $governance->open($ballot, Carbon::now()->addHours($hours));
            $ballot->refresh();

            $lines[] = 'opened   #'.$ballot->id.'  '.$ballot->title.'  closes '.$ballot->closes_at?->toIso8601ZuluString();

            $resident = Resident::query()->with('household')->where('email', MobileTestAccounts::RESIDENT_EMAIL)->first();
            $unit = $resident?->household?->unit_id === null ? null : Unit::query()->find($resident->household->unit_id);

            if ($unit instanceof Unit) {
                $eligibility = $governance->eligibility($unit);
                $lines[] = 'test household '.$unit->reference.': '.($eligibility['eligible'] ? 'ELIGIBLE' : 'NOT eligible ('.$eligibility['reason'].') — the app must show the refusal');
            } else {
                $lines[] = 'test household: not found — run `php artisan mobile:test-accounts` first';
            }

            return $lines;
        });

        foreach ($lines as $line) {
            $this->line($line);
        }

        if ($lines === []) {
            $this->line('nothing to close');
        }

        return self::SUCCESS;
    }
}
