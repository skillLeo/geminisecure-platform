<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Guard;
use App\Services\Devices\DeviceEnrolment;
use DomainException;
use Illuminate\Console\Command;

/**
 *   php artisan device:enrolment-code GS-1041
 *
 * Issues the one-time code a guard types into the Guard App to enrol their
 * handset (`POST /api/v1/devices/enrol`, 13 D1). Shown once; only its hash is
 * kept; it works for 24 hours and once. A guard who already has a handset gets
 * a rebind request instead, which a supervisor approves on the guard's profile.
 */
class IssueEnrolmentCode extends Command
{
    protected $signature = 'device:enrolment-code {guard : the guard\'s employee number, e.g. GS-1041}';

    protected $description = 'Issue a one-time code a guard uses to enrol a handset from the Guard App';

    public function handle(DeviceEnrolment $enrolment): int
    {
        $number = (string) $this->argument('guard');
        $guard = Guard::query()->where('employee_number', $number)->first();

        if ($guard === null) {
            $this->error("No guard with employee number [{$number}].");

            return self::FAILURE;
        }

        try {
            $code = $enrolment->issueCode($guard);
        } catch (DomainException $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->info("Enrolment code for {$guard->full_name} ({$number}):");
        $this->newLine();
        $this->line('  '.$code);
        $this->newLine();
        $this->warn('Shown once, valid for '.DeviceEnrolment::CODE_HOURS.' hours and one enrolment. It is not stored and cannot be recovered.');

        return self::SUCCESS;
    }
}
