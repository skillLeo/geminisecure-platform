<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Enums\AccessScope;
use App\Models\ClientAdoption;
use App\Models\User;
use App\Services\Platform\OperatorEstates;
use App\Support\PlatformCalendar;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Client health — board screen super-admin-07.
 *
 * How much of what each client bought they are actually using, one card per
 * client, a bar per capability.
 *
 * IT READS THE ROLL-UP, NEVER AN ESTATE DATABASE. That constraint is stated on
 * this report's own catalogue card and it is the reason the screen waited for
 * `client_adoption` to exist: adoption of the estate's own modules is recorded
 * inside the estate's own database, and a console that opened each one in turn
 * to build a cross-client report would be a tenant-isolation breach wearing a
 * report's clothes. So the fact's owner rolls it up and this reads the roll-up.
 *
 * A CAPABILITY NOBODY HAS REPORTED ON IS NOT NOUGHT PER CENT. It is listed
 * under the bars, by name, as awaiting a report — because a client shown at the
 * bottom of a health list for a capability nobody has ever counted would have an
 * account manager chasing them over the platform's own gap.
 *
 * THE SCORE IS THE MEAN OF WHAT IS MEASURED, and it says how many capabilities
 * it is a mean OF. A single bar at 100% is not the same claim as four at 100%,
 * and a chip reading "Healthy" off one measurement would make it look like one.
 *
 * NO MONEY AND NO NAMES LEAVE THIS CLASS. Every figure is two integers out of
 * `client_adoption`, which holds neither.
 */
class ClientHealth
{
    /** At or above this, a client is being used as intended. */
    private const HEALTHY = 75;

    /** Below HEALTHY and at or above this, they have started. */
    private const STARTED = 40;

    /**
     * How long a roll-up row stays trustworthy.
     *
     * A count nobody has refreshed in a fortnight is a stale answer, and the
     * card says so rather than presenting it as current. Silence from a client
     * is itself a health signal.
     */
    private const STALE_AFTER_DAYS = 14;

    /**
     * Every client, with their bars.
     *
     * @return array<string, mixed>
     */
    public function forViewer(User $viewer): array
    {
        $estates = $this->scoped($viewer, DB::connection('mysql')->table('tenants'))
            ->orderBy('name')
            ->get(['id', 'name', 'status']);

        $adoption = ClientAdoption::query()
            ->whereIn('tenant_id', $estates->pluck('id'))
            ->orderBy('label')
            ->get()
            ->groupBy('tenant_id');

        return [
            'clients' => $estates
                ->map(fn (object $estate): array => $this->card($estate, $adoption->get($estate->id, collect())))
                ->all(),
            'scoped' => $this->isScoped($viewer),

            /*
             * Named on the screen rather than left to be inferred. A reader
             * looking at a bar has to be able to find out what was counted, and
             * "adoption" means nothing on its own.
             */
            'awaiting' => 'Dues & ledger, facilities, governance and the estate\'s own payroll are counted inside each estate\'s database. They appear here once that estate\'s console reports them — this console never opens an estate database to find out.',
        ];
    }

    /**
     * One client's card.
     *
     * @param  Collection<int, ClientAdoption>  $rows
     * @return array<string, mixed>
     */
    private function card(object $estate, $rows): array
    {
        $measured = $rows->filter(static fn (ClientAdoption $row): bool => $row->isMeasurable());

        $bars = $measured
            ->map(fn (ClientAdoption $row): array => [
                'key' => $row->module_key,
                'label' => $row->label,
                'pct' => $row->percentage(),
                'title' => $this->barTitle($row),
            ])
            ->values()
            ->all();

        /*
         * Two reasons a capability has no bar, and they are different things to
         * tell an account manager: nobody has counted it, or there was nothing
         * to count. The first is the platform's gap, the second is a client who
         * has not set anything up yet.
         */
        $unmeasured = $rows
            ->reject(static fn (ClientAdoption $row): bool => $row->isMeasurable())
            ->map(static fn (ClientAdoption $row): array => [
                'label' => $row->label,
                'reason' => 'Nothing to measure yet — this client has no '.strtolower($row->label).' to take up.',
            ])
            ->values()
            ->all();

        $score = $bars === []
            ? null
            : (int) round(array_sum(array_column($bars, 'pct')) / count($bars));

        return [
            'id' => (string) $estate->id,
            'name' => (string) $estate->name,
            'bars' => $bars,
            'unmeasured' => $unmeasured,
            'score' => $score,
            'score_class' => $this->scoreClass($score),
            'score_label' => $this->scoreLabel($score, $estate),
            'score_title' => $this->scoreTitle($score, count($bars)),
        ];
    }

    /** What the two integers behind a bar count, and how fresh they are. */
    private function barTitle(ClientAdoption $row): string
    {
        $parts = [$row->detail ?? sprintf('%d of %d.', $row->adopted, $row->eligible)];

        $parts[] = $row->measured_at->diffInDays(now()) >= self::STALE_AFTER_DAYS
            ? sprintf('Last reported %s — this figure is out of date.', $row->measured_at->diffForHumans())
            : sprintf('Reported %s.', $this->measuredAt($row->measured_at));

        return implode(' ', $parts);
    }

    /** On Gemini's clock: `measured_at` is UTC, and "today, 1:15 AM" was a Kingston evening's reading (M11 §4.2). */
    private function measuredAt(Carbon $at): string
    {
        $local = PlatformCalendar::local($at);

        return $local->isToday() ? 'today, '.\App\Support\SiteTime::fmt($local, 'g:i A') : $at->diffForHumans();
    }

    /** The board's two chip treatments, plus the one it never had to draw. */
    private function scoreClass(?int $score): string
    {
        return match (true) {
            $score === null => 'med',
            $score >= self::HEALTHY => 'high',
            $score >= self::STARTED => 'med',
            default => 'low',
        };
    }

    /**
     * The words on the chip.
     *
     * A client nobody has reported on gets "Not yet reported" rather than a
     * score, because an unmeasured client and a struggling one look identical
     * on a bar chart and are opposite problems.
     */
    private function scoreLabel(?int $score, object $estate): string
    {
        if ($score === null) {
            return $estate->status === 'onboarding' ? 'Onboarding' : 'Not yet reported';
        }

        return match (true) {
            $score >= self::HEALTHY => 'Healthy',
            $score >= self::STARTED => 'Getting started',
            default => 'At risk',
        };
    }

    /** How many capabilities the chip is a mean of. Never left implied. */
    private function scoreTitle(?int $score, int $bars): string
    {
        if ($score === null) {
            return 'No capability has been counted for this client yet, so there is no score to give.';
        }

        return sprintf(
            '%d%% across %s.',
            $score,
            $bars === 1 ? 'one measured capability' : $bars.' measured capabilities',
        );
    }

    /**
     * Narrow to the clients this viewer may see — in the query, never the
     * render. A rendered filter leaves every other client one URL away.
     */
    private function scoped(User $viewer, Builder $query): Builder
    {
        /*
         * Scope AND the demonstration estate, in one call (M5 §3).
         *
         * This report is the one that asks "are our clients actually using what
         * they bought". The demonstration estate is not a client and is not
         * using anything — it is a fixture that `demo:seed` rebuilds — so its
         * bars are neither good news nor bad, and a health list it appears in is
         * a list with a row nobody is meant to act on. Its own record is still
         * one URL away at `/clients/demoestate` for anybody who wants to look.
         */
        OperatorEstates::narrow($query, $viewer, 'id');

        return $query;
    }

    private function isScoped(User $viewer): bool
    {
        return $viewer->widestScope() === AccessScope::AssignedSites;
    }
}
