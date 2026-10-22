<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Guard;
use App\Services\Devices\DeviceEnrolment;
use DomainException;
use Illuminate\Console\Command;

/**
 * Bind a handset to a guard and print its token once.
 *
 *   php artisan device:enrol GS-1041 --label="Samsung A15 · Main Gate"
 *   php artisan device:enrol GS-1041 --revoke
 *
 * How a real deployment bootstraps a phone. The Guard App will eventually do
 * this over an enrolment code, but somebody has to be able to issue the first
 * token from the machine that runs the platform, and an API nothing can reach
 * is an API nobody can test.
 *
 * THE TOKEN IS PRINTED ONCE AND NEVER STORED. Sanctum keeps a hash; if this
 * output is lost the handset is enrolled again, which is a thirty-second job
 * and the correct trade for not keeping a working credential in a database
 * somebody can read.
 */
class EnrolDevice extends Command
{
    protected $signature = 'device:enrol
        {guard : the guard\'s employee number, e.g. GS-1041}
        {--label= : what the handset is, for the roster to show}
        {--revoke : take the handset out of service instead of enrolling one}';

    protected $description = 'Bind a handset to a guard and issue its API token';

    public function handle(DeviceEnrolment $enrolment): int
    {
        $number = (string) $this->argument('guard');

        $guard = Guard::where('employee_number', $number)->first();

        if ($guard === null) {
            $this->error("No guard with employee number [{$number}].");

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            $enrolment->revoke($guard);

            $this->info("{$guard->full_name}'s handset is out of service. Its token no longer works.");

            return self::SUCCESS;
        }

        try {
            $result = $enrolment->enrol($guard, $this->option('label'));
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Enrolled a handset for {$guard->full_name} ({$number}).");
        $this->newLine();
        $this->line('  Device id  '.$result['device_id']);
        $this->line('  Token      '.$result['token']);
        $this->newLine();

        /*
         * Said plainly rather than left implicit. Somebody reading this output
         * has a working credential on their screen, and the one thing they need
         * to know is that it will not be shown again.
         */
        $this->warn('This token is shown once. It is not stored and cannot be recovered — enrol again if it is lost.');
        $this->line('Abilities: '.implode(', ', $result['abilities']));

        return self::SUCCESS;
    }
}
