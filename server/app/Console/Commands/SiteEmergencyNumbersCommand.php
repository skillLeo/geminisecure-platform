<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SiteEmergencyNumber;
use App\Models\Tenant;
use App\Services\Dispatch\PanicSmsOutcome;
use App\Services\Dispatch\SiteEmergencyNumbers;
use App\Services\Platform\SmsSettings;
use App\Support\JamaicanNumber;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * READ AND CHANGE THE NUMBERS A PANIC AT A SITE IS TEXTED TO (Q-M09).
 *
 * The numbers are rows, per estate, so that changing one is not a code change. This is
 * how they are changed TODAY. It is an honest interim and not the finished answer: the
 * finished answer is a panel on the estate's own settings screen, which is not built
 * (reported as unfinished). Until it is, this is the one tool that exists, it is the
 * same service the seeder and the panic path use, and nothing about it is a second way
 * of storing anything.
 *
 *   php artisan site:emergency-numbers phoenixpark
 *   php artisan site:emergency-numbers phoenixpark --set="president|President|1-876-508-8082"
 *   php artisan site:emergency-numbers phoenixpark --disable="1-876-508-8082"
 *
 * `--set` is `role|label|number`. The number is stored EXACTLY as typed; the diallable
 * form is derived beside it and printed back, so whoever ran this can see how the
 * platform read what they typed before an emergency does.
 *
 * NOTHING IS DELETED. A number that is no longer texted is deactivated, because
 * `alert_sms_dispatches` points at it and the history of who was told has to stay
 * readable.
 */
class SiteEmergencyNumbersCommand extends Command
{
    protected $signature = 'site:emergency-numbers
        {tenant : the estate id, e.g. phoenixpark}
        {--set=* : role|label|number — add one or correct one}
        {--disable=* : a number to stop texting, as it is stored}';

    protected $description = 'List, add or retire the numbers a panic at a site is texted to.';

    public function handle(SiteEmergencyNumbers $numbers, SmsSettings $sms): int
    {
        $tenantId = (string) $this->argument('tenant');

        if (Tenant::query()->whereKey($tenantId)->doesntExist()) {
            $this->error('No estate ['.$tenantId.'].');

            return self::FAILURE;
        }

        foreach ((array) $this->option('set') as $spec) {
            $parts = array_map('trim', explode('|', (string) $spec));

            if (count($parts) !== 3 || $parts[2] === '') {
                $this->error('--set wants role|label|number, e.g. "president|President|1-876-508-8082". Got: '.$spec);

                return self::FAILURE;
            }

            [$role, $label, $number] = $parts;

            if (! array_key_exists($role, SiteEmergencyNumbers::ROLES)) {
                $this->error('No role ['.$role.']. One of: '.implode(', ', array_keys(SiteEmergencyNumbers::ROLES)));

                return self::FAILURE;
            }

            /*
             * ONE PHONE, ONE ROW (bug hunt B101). The same phone typed another way corrects
             * the row it already has, in the place it already stands; the same phone under
             * another role is refused with the row it would have collided with. It used to
             * print "Added" and leave two rows texting one phone.
             */
            try {
                $record = $numbers->put($tenantId, $role, $label, $number);
            } catch (InvalidArgumentException $refused) {
                $this->error($refused->getMessage());

                return self::FAILURE;
            }

            $this->info(($record->wasRecentlyCreated ? 'Added' : 'Updated').': '.$record->label.' '.$record->number);

            if (! $record->isRoutable()) {
                $this->warn('  …but the platform cannot dial that. It will be recorded as UNROUTABLE and texted to nobody.');
            }
        }

        foreach ((array) $this->option('disable') as $number) {
            $affected = SiteEmergencyNumber::query()
                ->where('tenant_id', $tenantId)
                ->where('number', trim((string) $number))
                ->update(['is_active' => false]);

            $this->info($affected === 0 ? 'No number ['.$number.'] at this estate.' : 'Retired: '.$number);
        }

        $rows = SiteEmergencyNumber::query()->where('tenant_id', $tenantId)->orderBy('sort')->orderBy('id')->get();

        if ($rows->isEmpty()) {
            $this->warn('No emergency numbers on file. A panic here texts nobody, and the platform says so honestly.');

            return self::SUCCESS;
        }

        /*
         * "TEXTED? yes" ONLY WHEN SOMETHING CAN TEXT (M10 §1). This column used to
         * answer from the row alone, so an operator checking the four numbers before
         * a demonstration was told "yes" four times on a platform with no provider —
         * and would have told the President to expect a text on the next panic. A
         * number that is active and diallable is texted only once a provider is
         * connected, and the column now says which of the two is missing.
         */
        $canSend = $sms->canSend();

        $this->table(
            ['#', 'Role', 'Label', 'As given', 'Dialled as', 'Texted?'],
            $rows->map(fn (SiteEmergencyNumber $row): array => [
                $row->sort,
                $row->role,
                $row->label,
                $row->number,
                $row->e164 ?? '— cannot be dialled —',
                match (true) {
                    ! $row->is_active => 'no — retired',
                    ! $row->isRoutable() => 'no — unroutable',
                    ! $canSend => 'no — no SMS provider is connected',
                    default => 'yes',
                },
            ])->all(),
        );

        if (! $canSend) {
            $this->warn(PanicSmsOutcome::NO_PROVIDER.' A panic here is recorded against these numbers and delivered to none of them until a provider is connected.');
        }

        $this->line('Stored exactly as typed; the dialled form is worked out beside it ('.JamaicanNumber::class.').');

        return self::SUCCESS;
    }
}
