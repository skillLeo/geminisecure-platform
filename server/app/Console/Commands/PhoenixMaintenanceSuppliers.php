<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Estate\Vendor;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 *   php artisan phoenix:maintenance-suppliers --estate=phoenixparkvillage1             what it would change
 *   php artisan phoenix:maintenance-suppliers --estate=phoenixparkvillage1 --confirm   change it
 *
 * THE MAINTENANCE SUPPLIERS ON STEP 4 (DR-035, M62 A8; M73 §2.3, command 2). Assign vendor offered "Jamaica Public Service
 * Co." and "Gate2 Contractor Ltd". The electricity company comes off the list and stays a supplier for its monthly bills:
 * it is filed under Utilities, which a work order never offers (`Vendor::takesMaintenanceWork()`). "Gate2 Contractor
 * Ltd" is renamed to a plausible Jamaican firm, the same record with the same bills, tickets and history.
 *
 * Nothing else moves: no bill, payment, ticket or other supplier, and nothing is deleted. One audit entry records
 * before and after. Without --confirm nothing is written. Run on production by a person who has read the dry run,
 * never by a deploy.
 */
class PhoenixMaintenanceSuppliers extends Command
{
    protected $signature = 'phoenix:maintenance-suppliers
        {--estate=phoenixparkvillage1 : the estate}
        {--confirm : change them; without it nothing is written}';

    protected $description = 'Take the electricity company off the maintenance list (kept for bills) and rename Gate2 Contractor Ltd (DR-035)';

    public const UTILITY = 'Jamaica Public Service Co.';

    public const OLD_NAME = 'Gate2 Contractor Ltd';

    public const NEW_NAME = 'Eastgate Barrier & Fencing Ltd';

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
            $this->info(($apply ? 'Setting the maintenance suppliers at ' : 'What --confirm would change at ').$estate->name.' ('.$key.')'.($apply ? '' : '. Nothing is written without --confirm.'));

            $utility = Vendor::query()->where('name', self::UTILITY)->first();
            $gate = Vendor::query()->where('name', self::OLD_NAME)->first();
            $taken = Vendor::query()->where('name', self::NEW_NAME)->exists();
            $rows = [];
            $changes = [];

            if ($utility === null) {
                $rows[] = [self::UTILITY, 'no such supplier', 'left alone'];
            } elseif ($utility->category === Vendor::UTILITIES) {
                $rows[] = [self::UTILITY, 'already filed under Utilities: off the maintenance list, kept for bills', 'nothing to do'];
            } else {
                $rows[] = [self::UTILITY, 'category "'.($utility->category ?? 'none').'"', 'category "'.Vendor::UTILITIES.'": off the maintenance list, kept for bills ('.$utility->status.')'];
                $changes['utility'] = ['id' => (int) $utility->id, 'before' => $utility->category, 'after' => Vendor::UTILITIES];
            }

            if ($gate === null) {
                $rows[] = [self::OLD_NAME, $taken ? 'already renamed' : 'no such supplier', 'left alone'];
            } elseif ($taken) {
                $rows[] = [self::OLD_NAME, 'a supplier is already called "'.self::NEW_NAME.'"', 'left alone: two suppliers never share a name'];
            } else {
                $rows[] = [self::OLD_NAME, 'its bills, tickets and history stay on the same record', 'renamed "'.self::NEW_NAME.'"'];
                $changes['rename'] = ['id' => (int) $gate->id, 'before' => self::OLD_NAME, 'after' => self::NEW_NAME];
            }

            $this->newLine();
            $this->table(['Supplier', 'Now', 'After'], $rows);
            $this->line('NOT TOUCHED: any bill, payment, ticket or other supplier; nothing is deleted.');

            if ($changes === []) {
                $this->newLine();
                $this->info('Nothing to change. Nothing was changed.');

                return self::SUCCESS;
            }

            if (! $apply) {
                $this->newLine();
                $this->info(count($changes).' change'.(count($changes) === 1 ? '' : 's').' would be made. Nothing is written without --confirm.');

                return self::SUCCESS;
            }

            DB::connection('tenant')->transaction(function () use ($changes): void {
                if (isset($changes['utility'])) {
                    Vendor::query()->whereKey($changes['utility']['id'])->firstOrFail()->forceFill(['category' => Vendor::UTILITIES])->save();
                }

                if (isset($changes['rename'])) {
                    Vendor::query()->whereKey($changes['rename']['id'])->firstOrFail()->forceFill(['name' => self::NEW_NAME])->save();
                }
            });

            $audit->record(
                action: 'vendor.maintenance_suppliers_set',
                entityType: 'Estate',
                entityId: $key,
                before: array_map(static fn (array $c): mixed => $c['before'], $changes),
                after: array_map(static fn (array $c): mixed => $c['after'], $changes),
                tenantId: $key,
            );

            $this->newLine();
            $this->info('Done: '.count($changes).' change'.(count($changes) === 1 ? '' : 's').' made.');

            return self::SUCCESS;
        });
    }
}
