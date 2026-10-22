<?php

declare(strict_types=1);

namespace Database\Seeders\Estate;

use App\Models\Estate\EstateSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The estate's own settings row — boards 21 and 23.
 *
 * SMALL ON PURPOSE, AND MOST OF WHAT THESE SCREENS DRAW IS NOT SEEDED HERE
 * BECAUSE IT IS NOT SEEDED ANYWHERE:
 *
 *   board 21  the estate name, address, unit count and phase count are the
 *             central client record and this estate's own units. Seeding a
 *             second copy is precisely what the migration refuses to hold a
 *             column for
 *   board 22  users, roles and assignments are central and belong to
 *             DemoDataSeeder
 *   board 23  the feature catalogue is central and belongs to
 *             PlatformCatalogueSeeder
 *   board 24  the matrix is central and belongs to RbacMatrixSeeder
 *
 * NO `estate_features` ROW IS WRITTEN, AND THAT IS THE WHOLE DESIGN. A row in
 * that table means somebody in this community made a decision; board 23 draws
 * every switch on because Phoenix Park is on the Premium plan and Premium
 * includes everything, which is the PLAN's answer and not the estate's.
 * Seeding eleven rows saying "enabled" would put eleven decisions nobody made
 * into the record, and the day the plan changed they would outvote it.
 *
 * IDEMPOTENT, AND WRITE-ONCE ON EVERY FIELD IT TOUCHES. The contact details
 * below are seeded only where the estate has none. A seeder that reset them on
 * every run would quietly undo a committee correcting its own enquiries
 * address — the same rule PayablesSeeder applies to a vendor's TRN, and for the
 * same reason: a seed is a starting point, not a periodic instruction.
 */
class SettingsSeeder extends Seeder
{
    /**
     * Board 21's contact block, per estate.
     *
     * PHOENIX PARK'S FOLLOW THE BOARD, but not its mailbox. The board drew
     * `info@phoenixpark1.org` — a domain nobody has registered, which anybody
     * could, and then read every enquiry a resident's notice sends there. A
     * seeded address reaches nobody (M35 §5), so it keeps its name on the
     * domain kept for examples; the estate types its own when it has one.
     *
     * OCEAN VIEW HAS NONE, and none is invented for it. No board draws Ocean
     * View's settings, it is mid-onboarding on every board that does draw it,
     * and an estate that has not yet published an enquiries address is a real
     * state this screen has to be able to render. An invented address would
     * also be a mailbox nobody reads printed on a resident notice.
     *
     * @var array<string, array{email: string, phone: string}>
     */
    private const CONTACTS = [
        'phoenixpark' => ['email' => 'info@example.org', 'phone' => '(876) 555 0100'],
    ];

    public function run(): void
    {
        /*
         * Which estate this is.
         *
         * `tenant()` inside `$tenant->run()`, and the database name where there
         * is no tenant at all — which is how the traceability suite builds an
         * estate of its own, pointing the connection straight at a database
         * with no central record behind it.
         */
        $key = (string) (
            tenant()?->getTenantKey()
            ?? Str::after(
                DB::connection('tenant')->getDatabaseName(),
                (string) config('tenancy.database.prefix'),
            )
        );

        // Creates the row with every default the migrations declare, which is
        // the point of `current()`: a caller reading a threshold always gets a
        // number and never has to decide what a missing setting means.
        $setting = EstateSetting::current();

        $contact = self::CONTACTS[$key] ?? null;

        if ($contact === null) {
            return;
        }

        $changed = false;

        // Write-once, field by field. An estate that has an email and no phone
        // gets the phone, and keeps the email it has.
        if ($setting->enquiries_email === null) {
            $setting->enquiries_email = $contact['email'];
            $changed = true;
        }

        if ($setting->enquiries_phone === null) {
            $setting->enquiries_phone = $contact['phone'];
            $changed = true;
        }

        if ($changed) {
            $setting->save();
        }
    }
}
