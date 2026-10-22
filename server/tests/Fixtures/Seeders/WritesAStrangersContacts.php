<?php

declare(strict_types=1);

namespace Tests\Fixtures\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A SEEDER THAT DOES THE WRONG THING, ON PURPOSE (M35 §5.4) — never run outside `SeedersWriteOnlyUndeliverableContactsTest`.
 *
 * It writes one invented resident with an address on a live public mail service and a number a phone can answer, which
 * is exactly what the demonstration seed did. The test reads this file and the row it writes, and both must fail.
 */
final class WritesAStrangersContacts extends Seeder
{
    public function run(): void
    {
        DB::connection('tenant')->table('residents')->insert([
            'household_id' => (int) DB::connection('tenant')->table('households')->orderByDesc('id')->value('id'),
            'full_name' => 'Somebody Invented',
            'email' => 'somebody.invented@gmail.com',
            'phone' => '876 508 8082',
            'relationship' => 'household member',
            'is_primary' => false,
            'status' => 'verified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
