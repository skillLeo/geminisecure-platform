<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Estate\OnePrimaryIndex;
use App\Services\Estate\PrimaryResidentRepair;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 *   php artisan residents:repair-primaries [--estate=] [--household= --keep=] [--dry-run]
 *
 * A household left with two primary residents is given one (DESIGN §13.4). Per estate, every household holding
 * more than one CURRENT primary keeps one — the lowest id, which is who `Household::primaryResident()` already
 * shows, unless `--keep` names another of that household's primaries — and the rest stop being primary. Each
 * household repaired is audited (`residents.primary_repaired`, with what was kept, what was demoted, why, and the
 * evidence), and a second run finds nothing to do.
 *
 * THE DEPLOY RUNS ONLY `--dry-run` (G11). The known case is the user's own household, where the SECOND primary
 * is the one who has been adding and inviting members; lowest-id would quietly take that from them. So the dry
 * run's report — who each primary is, when they were added, who verified them, how often each has acted as the
 * primary in the app, and the claim that made them — goes to the person who knows the household, and the real
 * run is theirs to ask for, one household at a time:
 *
 *   php artisan residents:repair-primaries --estate=phoenixpark --household=38 --keep=1207
 *
 * Nothing waits on it: `Resident`'s guard fires only when a row BECOMES primary, so a pair left standing locks
 * nobody out, and no new pair can be made.
 *
 * AND THEN THE DATABASE HOLDS IT (M47 §14a). Once a real run leaves an estate with no pair, the unique index that
 * refuses a second primary goes on there at once, as the schema owner (OnePrimaryIndex) — and every report says
 * whether an estate's database refuses one yet.
 */
class RepairPrimaries extends Command
{
    protected $signature = 'residents:repair-primaries
        {--estate= : one estate (its subdomain); every estate when left out}
        {--household= : one household id — needs --estate, because a household id is one estate\'s}
        {--keep= : the resident id the household keeps as primary — needs --household}
        {--all-households : a REAL run over every household the report lists, keeping the lowest id in each}
        {--dry-run : say what would be done, and change nothing}';

    protected $description = 'Give every household that has more than one primary resident exactly one, reporting the evidence for each';

    public function handle(PrimaryResidentRepair $repair): int
    {
        $estateKey = $this->stringOption('estate');
        $dryRun = (bool) $this->option('dry-run');

        foreach (['household', 'keep'] as $name) {
            $given = $this->stringOption($name);

            if ($given !== null && ! ctype_digit($given)) {
                $this->error(sprintf('--%s takes an id (digits only), not "%s". Nothing was changed.', $name, $given));

                return self::INVALID;
            }
        }

        $household = $this->intOption('household');
        $keep = $this->intOption('keep');

        if ($household !== null && $estateKey === null) {
            $this->error('A household id belongs to one estate: name it with --estate as well. Nothing was changed.');

            return self::INVALID;
        }

        if ($keep !== null && $household === null) {
            $this->error(PrimaryResidentRepair::KEEP_NEEDS_HOUSEHOLD.' Nothing was changed.');

            return self::INVALID;
        }

        /*
         * A REAL RUN IS A CHOICE ABOUT PEOPLE, SO IT IS NEVER THE DEFAULT (G11). Forgetting --dry-run once would
         * demote every second primary on every estate by lowest id — in the known household, the one who has been
         * adding and inviting its members. So a real run names its household, or says --all-households on purpose.
         */
        if (! $dryRun && $household === null && ! (bool) $this->option('all-households')) {
            $this->error('A real run changes who is primary. Name one household with --estate and --household (and --keep to choose who stays), or pass --all-households to keep the lowest id in every household the dry run lists. Nothing was changed.');

            return self::INVALID;
        }

        $estates = Tenant::estates($estateKey === null ? null : static function (Builder $query) use ($estateKey): void {
            $query->whereKey($estateKey)->orderBy('id');
        });

        if ($estates->isEmpty()) {
            $this->warn($estateKey === null ? 'No estates provisioned — nothing to repair.' : 'No estate "'.$estateKey.'". Nothing was changed.');

            return $estateKey === null ? self::SUCCESS : self::INVALID;
        }

        $this->info($dryRun ? 'DRY RUN — nothing is changed. Households with more than one primary resident:' : 'Households with more than one primary resident:');

        $failed = 0;
        $refused = false;

        foreach ($estates as $estate) {
            try {
                /** @var array{0: list<array{household_id: int, household: string, unit: string, chosen_by: string, kept: array<string, mixed>, demoted: list<array<string, mixed>>}>, 1: string, 2: bool, 3: int} $answer */
                $answer = $estate->run(fn (): array => [$repair->repair($household, $keep, $dryRun), DB::connection('tenant')->getDatabaseName(), OnePrimaryIndex::inPlace(), OnePrimaryIndex::pairs()]);
                [$report, $database, $enforced, $pairsLeft] = $answer;
            } catch (DomainException $refusal) {
                // stancl's `run()` puts nothing back when its callback throws.
                tenancy()->end();
                $this->error($estate->getTenantKey().': '.$refusal->getMessage());
                $refused = true;

                continue;
            } catch (Throwable $failure) {
                tenancy()->end();
                $failed++;
                $this->line('  <fg=red>FAIL</> '.$estate->getTenantKey().': '.$failure->getMessage());

                continue;
            }

            // A real run that left this estate with no pair puts the index on now, as the schema owner — tenancy has
            // ended, so `tenant` can be pointed at the owner's credentials (OnePrimaryIndex::ensureAsOwner).
            if (! $dryRun && ! $enforced && $pairsLeft === 0) {
                $enforced = OnePrimaryIndex::ensureAsOwner($database);
            }

            if ($report === []) {
                $this->line('  '.$estate->getTenantKey().': none — every household has at most one.');
                $this->line('      '.$this->held($enforced, $pairsLeft));

                continue;
            }

            foreach ($report as $row) {
                $this->line(sprintf(
                    '  %s · household %d (%s%s) · %s %s · %s %s',
                    $estate->getTenantKey(),
                    $row['household_id'],
                    $row['household'],
                    $row['unit'] === '' ? '' : ', '.$row['unit'],
                    $dryRun ? 'would keep' : 'kept',
                    $this->person($row['kept']),
                    $dryRun ? 'would demote' : 'demoted',
                    implode('; ', array_map(fn (array $person): string => $this->person($person), $row['demoted'])),
                ));
                $this->line('      chosen by '.($row['chosen_by'] === '--keep' ? '--keep' : 'lowest id (what every screen already shows); run with --household='.$row['household_id'].' --keep=<resident id> to keep another'));
            }

            $this->line('      '.$this->held($enforced, $pairsLeft));
        }

        if ($refused) {
            return self::INVALID;
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Whether this estate's database itself refuses a second primary yet — and, when it does not, what makes it.
     *
     * @param  bool|null  $enforced  null: the schema owner could not be reached to put the index on
     */
    private function held(?bool $enforced, int $pairsLeft): string
    {
        if ($enforced === true) {
            return 'The database refuses a second primary in any household here.';
        }

        if ($pairsLeft > 0) {
            return 'The database will refuse a second primary here once every household with two has been given one — the real run puts it on at once.';
        }

        return 'No household here has two: the database refuses a second primary after a real run of this command, or the next `php artisan estates:migrate` (both as the schema owner).';
    }

    /** @param array<string, mixed> $person */
    private function person(array $person): string
    {
        $claim = $person['claim_id'] === null
            ? 'no approved claim'
            : sprintf('claim %d approved by %s%s', $person['claim_id'], (string) ($person['claim_approved_by'] ?? 'nobody recorded'), $person['claim_approved_at'] === null ? '' : ' on '.$person['claim_approved_at']);

        $account = is_array($person['app_account'] ?? null)
            ? sprintf('Resident App account %s, last changed %s', (string) $person['app_account']['status'], (string) ($person['app_account']['last_changed'] ?? 'unknown'))
            : 'no Resident App account';

        return sprintf(
            '#%d %s (%s; added %s, verified by %s, %d invite(s) sent and %d member(s) removed in the app, %s)',
            $person['id'],
            $person['name'],
            $account,
            (string) ($person['created_at'] ?? 'unknown'),
            (string) ($person['verified_by_name'] ?? 'nobody recorded'),
            $person['invites_sent_in_app'],
            $person['members_removed_in_app'],
            $claim,
        );
    }

    private function stringOption(string $name): ?string
    {
        $value = trim((string) $this->option($name));

        return $value === '' ? null : $value;
    }

    private function intOption(string $name): ?int
    {
        $value = $this->stringOption($name);

        return $value === null || ! ctype_digit($value) ? null : (int) $value;
    }
}
