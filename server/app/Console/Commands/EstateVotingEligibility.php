<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Estate\EstateSetting;
use App\Models\Estate\Household;
use App\Models\Estate\Unit;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;

/**
 *   php artisan estate:voting-eligibility --estate=phoenixparkvillage1                        who may vote now, counted both ways
 *   php artisan estate:voting-eligibility --estate=phoenixparkvillage1 --by=lots --reason="…"  what changing it would do
 *   … the same with --confirm                                                                  change it
 *
 * WHO MAY VOTE, AND SO WHAT A QUORUM IS A SHARE OF (Hassam, 9 October 2026). The households that exist, by default:
 * counted by lot, Phoenix Park's 25% quorum is 500 of 1,999 with about 443 households living there, and could never be
 * met. Every lot, vacant ones included, is the other rule: an absent owner keeps a vote. Which one an estate follows is
 * the client's to say (CONFIRM-01), so it is a setting, changed here with the reason on the audit log and never by a
 * settings form. A meeting or a ballot already drafted keeps the count it was given; the next ones take the new rule.
 */
class EstateVotingEligibility extends Command
{
    protected $signature = 'estate:voting-eligibility
        {--estate= : the estate, always named}
        {--by= : households (the households that exist) or lots (every lot, vacant ones included)}
        {--reason= : why, ten characters at least: the client\'s answer to CONFIRM-01}
        {--confirm : make the change; without it nothing is written}';

    protected $description = 'Say who may vote at an estate: the households that exist (the default) or every lot (dry run unless --confirm)';

    private const RULES = [
        EstateSetting::VOTES_BY_HOUSEHOLDS => 'the households that exist',
        EstateSetting::VOTES_BY_LOTS => 'every lot, vacant ones included',
    ];

    public function handle(AuditLogger $audit): int
    {
        $key = trim((string) $this->option('estate'));
        $estate = $key === '' ? null : Tenant::query()->find($key);

        if (! $estate instanceof Tenant) {
            $this->error('Name the estate: --estate=phoenixparkvillage1. There is no estate "'.$key.'". Nothing was changed.');

            return self::INVALID;
        }

        $by = trim((string) $this->option('by'));

        if ($by !== '' && ! array_key_exists($by, self::RULES)) {
            $this->error('--by is households or lots. Nothing was changed.');

            return self::INVALID;
        }

        $apply = (bool) $this->option('confirm');
        $reason = trim((string) $this->option('reason'));

        if ($apply && ($by === '' || mb_strlen($reason) < 10)) {
            $this->error('Say which and why: --by=households or --by=lots, and --reason="…" in ten characters at least. Nothing was changed.');

            return self::INVALID;
        }

        return $estate->run(function () use ($estate, $key, $by, $apply, $reason, $audit): int {
            $settings = EstateSetting::current();
            $now = (string) $settings->voting_eligibility;
            $percent = (int) $settings->meeting_quorum_percent;
            $counts = [
                // The count a meeting's quorum and the dashboard take (M87 §1.4): a household with a resident, on a lot.
                EstateSetting::VOTES_BY_HOUSEHOLDS => Household::query()->counted()->count(),
                EstateSetting::VOTES_BY_LOTS => Unit::lots()->count(),
            ];

            $this->info('Who may vote at '.$estate->name.' ('.$key.'): '.(self::RULES[$now] ?? $now));

            foreach ($counts as $rule => $count) {
                $this->line(sprintf('  %s %s: %s eligible; a %d%% quorum is %s.', $rule === $now ? '*' : ' ', self::RULES[$rule], number_format($count), $percent, number_format((int) ceil($count * $percent / 100))));
            }

            $this->line('A meeting or a ballot already drafted keeps the count it was given; the next ones take the rule.');

            if ($by === '' || $by === $now) {
                $this->info($by === '' ? 'Nothing named to change: --by=households or --by=lots.' : 'It is '.self::RULES[$now].' already. Nothing to change.');

                return self::SUCCESS;
            }

            if (! $apply) {
                $this->info('With --confirm: '.self::RULES[$by].', '.number_format($counts[$by]).' eligible. Nothing is written without --confirm.');

                return self::SUCCESS;
            }

            $settings->forceFill(['voting_eligibility' => $by])->save();

            $audit->record(
                action: 'estate.voting_eligibility_changed',
                entityType: 'EstateSetting',
                entityId: (string) $settings->getKey(),
                before: ['voting_eligibility' => $now],
                after: ['voting_eligibility' => $by, 'reason' => $reason, 'via' => 'estate:voting-eligibility'],
                tenantId: $key,
            );

            $this->info('Done: '.self::RULES[$by].', '.number_format($counts[$by]).' eligible, on the audit log.');

            return self::SUCCESS;
        });
    }
}
