<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Estate\Unit;
use App\Models\ResidentAccount;
use App\Models\Tenant;
use App\Services\Dispatch\PanicDrillMode;
use App\Services\ResidentApp\ResidentSignIn;
use App\Support\SiteTime;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 *   php artisan panic:drill-mode --estate=phoenixparkvillage1 --unit="Lot 47"        the accounts at that lot, and who is in drill mode now
 *   php artisan panic:drill-mode --estate=phoenixparkvillage1 --account=451 --minutes=120 --reason="Testing the panic button"
 *                                                                                    what switching it on would do
 *   … --confirm                                                                       switch it on
 *   php artisan panic:drill-mode --estate=phoenixparkvillage1 --account=451 --off --confirm
 *                                                                                    switch it off now
 *
 * DRILL MODE (M53 §3A.1, M54 §2.3): for one Resident App account, for a set time, a Panic is a DRILL that wakes nobody
 * (`PanicDrillMode`). It is set here, on the server, by a person who has read the dry run, and nowhere a handset can
 * reach. It ends by itself after at most four hours, and every switch on or off is audited.
 */
class PanicDrillModeCommand extends Command
{
    protected $signature = 'panic:drill-mode
        {--estate= : the estate (phoenixparkvillage1 on production)}
        {--unit= : list the Resident App accounts at this unit, e.g. "Lot 47"}
        {--account= : the Resident App account to switch, by its id from the list}
        {--minutes=120 : how long it stays on, from 1 to 240}
        {--reason= : why, in a few words; the phone shows it}
        {--off : switch it off now}
        {--confirm : make the change; without it, only say what it would be}';

    protected $description = 'Switch drill mode on or off for one Resident App account: while it is on, its Panic is a drill that wakes nobody';

    private const BY = 'Server command (panic:drill-mode)';

    public function handle(PanicDrillMode $drill): int
    {
        $key = (string) $this->option('estate');
        $estate = $key === '' ? null : Tenant::query()->find($key);

        if (! $estate instanceof Tenant) {
            $this->error('Say which estate: --estate=phoenixparkvillage1. There is no estate "'.$key.'". Nothing was changed.');

            return self::INVALID;
        }

        $id = (string) $this->option('account');

        if ($id === '') {
            return $this->listAccounts($estate, $key);
        }

        $account = ctype_digit($id) ? ResidentAccount::query()->where('tenant_id', $key)->find((int) $id) : null;

        if (! $account instanceof ResidentAccount) {
            $this->error('There is no Resident App account '.$id.' at '.$estate->name.'. List them with --unit="Lot 47". Nothing was changed.');

            return self::INVALID;
        }

        $who = $this->describe($account, $estate);
        $apply = (bool) $this->option('confirm');

        if ((bool) $this->option('off')) {
            if (! PanicDrillMode::isOn($account)) {
                $this->info('Drill mode is already off for '.$who.'. Nothing was changed.');

                return self::SUCCESS;
            }

            if (! $apply) {
                $this->info('DRY RUN. Drill mode would be switched OFF now for '.$who.'. From then on a Panic from that account is a real alert again.');
                $this->line('Nothing was changed. Add --confirm to switch it off.');

                return self::SUCCESS;
            }

            $drill->switchOff($account, self::BY);
            $this->info('Drill mode is OFF for '.$who.'. A Panic from that account is a real alert again.');

            return self::SUCCESS;
        }

        $minutes = (string) $this->option('minutes');

        if (! ctype_digit($minutes) || (int) $minutes < 1 || (int) $minutes > PanicDrillMode::MAX_MINUTES) {
            $this->error('--minutes is how long drill mode stays on: a whole number from 1 to '.PanicDrillMode::MAX_MINUTES.'. Nothing was changed.');

            return self::INVALID;
        }

        $reason = trim((string) $this->option('reason'));

        if ($reason === '') {
            $this->error('Say why, in a few words: --reason="Testing the panic button". The phone shows it. Nothing was changed.');

            return self::INVALID;
        }

        $until = Carbon::now()->addMinutes((int) $minutes);
        $at = $this->clock($until, $key);

        $this->info(($apply ? '' : 'DRY RUN. ').'Drill mode '.($apply ? 'is' : 'would be').' ON for '.$who.' until '.$at.' at the estate ('.$minutes.' minutes).');
        $this->line('While it is on, a Panic from that account is recorded as a DRILL: no officer\'s phone rings, nobody is emailed or texted, and');
        $this->line('no siren sounds. It is still on every console, labelled DRILL, and kept. The phone shows "Drill mode is on" on the Panic screen.');

        if (! $apply) {
            $this->line('Nothing was changed. Add --confirm to switch it on.');

            return self::SUCCESS;
        }

        $drill->switchOn($account, (int) $minutes, $reason, self::BY);
        $this->line('It switches itself off at '.$at.'. To end it sooner:');
        $this->line('  php artisan panic:drill-mode --estate='.$key.' --account='.$account->id.' --off --confirm');

        return self::SUCCESS;
    }

    /** The accounts at a unit, and every account at the estate in drill mode now. */
    private function listAccounts(Tenant $estate, string $key): int
    {
        $on = ResidentAccount::query()->where('tenant_id', $key)->where('drill_mode_until', '>', Carbon::now())->orderBy('id')->get();

        $this->info('In drill mode now at '.$estate->name.': '.($on->isEmpty() ? 'nobody.' : ''));
        foreach ($on as $account) {
            $this->line('  '.$this->describe($account, $estate).', until '.$this->clock(Carbon::parse($account->drill_mode_until), $key));
        }

        $reference = trim((string) $this->option('unit'));

        if ($reference === '') {
            $this->newLine();
            $this->line('To pick an account, list a lot\'s: --unit="Lot 47". Nothing was changed.');

            return self::SUCCESS;
        }

        $unitId = $estate->run(static fn () => Unit::query()->where('reference', $reference)->value('id'));

        if ($unitId === null) {
            $this->error('There is no unit "'.$reference.'" at '.$estate->name.'. Nothing was changed.');

            return self::INVALID;
        }

        $accounts = ResidentAccount::query()->where('tenant_id', $key)->where('unit_id', (int) $unitId)->orderBy('id')->get();

        $this->newLine();
        $this->info('Resident App accounts at '.$reference.':'.($accounts->isEmpty() ? ' none.' : ''));
        foreach ($accounts as $account) {
            $this->line('  '.$this->describe($account, $estate).' · '.$account->status.(PanicDrillMode::isOn($account) ? ' · in drill mode' : ''));
        }

        $this->newLine();
        $this->line('Then: --account=<id> --minutes=120 --reason="Testing the panic button" for the dry run. Nothing was changed.');

        return self::SUCCESS;
    }

    /** "account 451 (a name, Lot 47, signs in as a masked address)": never the whole address. */
    private function describe(ResidentAccount $account, Tenant $estate): string
    {
        $unit = $account->unit_id === null ? null : $estate->run(static fn () => Unit::query()->whereKey($account->unit_id)->value('reference'));
        $parts = array_filter([
            $account->full_name ?: 'no name given',
            $unit === null ? null : (string) $unit,
            'signs in as '.ResidentSignIn::mask((string) $account->destination),
        ]);

        return 'account '.$account->id.' ('.implode(', ', $parts).')';
    }

    private function clock(Carbon $instant, string $key): string
    {
        return SiteTime::local($instant, $key)->format('g:i A \o\n j M');
    }
}
