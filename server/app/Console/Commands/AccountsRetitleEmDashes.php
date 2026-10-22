<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Estate\Account;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 *   php artisan accounts:retitle-em-dashes --estate=phoenixparkvillage1             what it would change
 *   php artisan accounts:retitle-em-dashes --estate=phoenixparkvillage1 --confirm   change it
 *
 * THE SEEDED CHART'S TWO OLD NAMES, WITHOUT THE EM DASH (Law 3; Hassam, 10 October). The demonstration seed named 5050
 * "Security Services — Gemini Security Ltd" and 5200 "Utilities — Common Areas", and every report, list and statement
 * that names an account printed the dash. The seed says them with brackets now (`ChartOfAccountsSeeder`); an estate
 * built from the old seed keeps the old names until this runs.
 *
 * An account is retitled only while its name is EXACTLY the old seeded one: an office that renamed it has chosen its
 * own words, and they are left alone. Nothing else moves: not the code, the type, a balance, a journal, or any other
 * account. Posted journals point at the account by its id, so every past line now reads the new name.
 *
 * Without --confirm nothing is written. Run on production by a person who has read the dry run, never by a deploy.
 */
class AccountsRetitleEmDashes extends Command
{
    protected $signature = 'accounts:retitle-em-dashes
        {--estate= : the estate (phoenixparkvillage1 on production)}
        {--confirm : write the new names; without it nothing is written}';

    protected $description = 'Retitle the two seeded accounts whose names carried an em dash, where the estate still holds the old names';

    /** The old seeded name and the seed's words now, by account code. */
    public const RETITLED = [
        '5050' => ['from' => 'Security Services — Gemini Security Ltd', 'to' => 'Security Services (Gemini Security Ltd)'],
        '5200' => ['from' => 'Utilities — Common Areas', 'to' => 'Utilities (Common Areas)'],
    ];

    public function handle(AuditLogger $audit): int
    {
        $key = (string) $this->option('estate');
        $estate = $key === '' ? null : Tenant::query()->find($key);

        if (! $estate instanceof Tenant) {
            $this->error('Say which estate: --estate=phoenixparkvillage1. There is no estate "'.$key.'". Nothing was changed.');

            return self::INVALID;
        }

        $apply = (bool) $this->option('confirm');

        return (int) $estate->run(function () use ($estate, $key, $apply, $audit): int {
            $this->info(($apply ? 'Retitling the seeded accounts at ' : 'What --confirm would retitle at ').$estate->name.' ('.$key.')'.($apply ? '' : '. Nothing is written without --confirm.'));

            // Codes are text in the chart ("5050"), though PHP keeps an array's numeric keys as numbers.
            $accounts = Account::query()->whereIn('code', array_map('strval', array_keys(self::RETITLED)))->get()->keyBy('code');
            $changes = [];
            $rows = [];

            foreach (self::RETITLED as $code => $names) {
                $account = $accounts[$code] ?? null;

                if (! $account instanceof Account) {
                    $rows[] = [$code, 'no such account', 'left alone'];

                    continue;
                }

                if ($account->name === $names['to']) {
                    $rows[] = [$code, $account->name, 'already reads so'];

                    continue;
                }

                if ($account->name !== $names['from']) {
                    $rows[] = [$code, $account->name, 'left alone: the office\'s own name'];

                    continue;
                }

                $changes[$code] = ['now' => $account->name, 'after' => $names['to']];
                $rows[] = [$code, $account->name, $names['to']];
            }

            $this->newLine();
            $this->table(['Code', 'Now', 'After'], $rows);
            $this->line('NOT TOUCHED: the codes, the types, any balance or journal, any other account.');

            if ($changes === []) {
                $this->newLine();
                $this->info('Nothing to retitle. Nothing was changed.');

                return self::SUCCESS;
            }

            if (! $apply) {
                $this->newLine();
                $this->info(count($changes).' account'.(count($changes) === 1 ? '' : 's').' would be retitled. Nothing is written without --confirm.');

                return self::SUCCESS;
            }

            DB::connection('tenant')->transaction(function () use ($changes, $accounts): void {
                foreach ($changes as $code => $change) {
                    $accounts[$code]->forceFill(['name' => $change['after']])->save();
                }
            });

            $before = [];
            $after = [];

            foreach ($changes as $code => $change) {
                $before['account '.$code] = $change['now'];
                $after['account '.$code] = $change['after'];
            }

            $audit->record(
                action: 'account.em_dash_names_retitled',
                entityType: 'Estate',
                entityId: $key,
                before: $before,
                after: $after,
                tenantId: $key,
            );

            $this->newLine();
            $this->info('Done: '.count($changes).' account'.(count($changes) === 1 ? '' : 's').' retitled.');

            return self::SUCCESS;
        });
    }
}
