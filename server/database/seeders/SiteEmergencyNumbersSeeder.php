<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SiteEmergencyNumber;
use App\Models\Tenant;
use App\Services\Dispatch\SiteEmergencyNumbers;
use Illuminate\Database\Seeder;

/**
 * THE FOUR NUMBERS A PANIC AT PHOENIX PARK VILLAGE 1 IS TEXTED TO (Q-M09).
 *
 * The client's ruling, verbatim:
 *
 *   "Additionally the SMS must be sent to: President (1-876-508-8082), Property Office
 *    (1-876-224-4444), PPV1 Head of Security (1-876-845-9422) and Gemini Dispatch
 *    (876-354-2119)."
 *
 * TRANSCRIBED, NOT TIDIED. Three of the four were given with a 1-876 country prefix
 * and the fourth without; all four are stored here exactly as the client wrote them.
 * The diallable form is worked out beside each (`Support\JamaicanNumber`) — Jamaica is
 * inside the North American Numbering Plan, so "876 354 2119" and "1 876 354 2119" are
 * one number written two ways, and all four resolve. Nothing is rewritten in place, so
 * an office reading this list back sees its own handwriting.
 *
 * SEEDED ONCE PER ESTATE AND NEVER AGAIN. If the estate already holds any numbers, this
 * seeder leaves them entirely alone — the same rule the notification defaults follow,
 * and for a sharper reason: a second `db:seed` that reinstated a president who left
 * would text the wrong person about an emergency, and nobody would know until it
 * happened.
 *
 * ONE ESTATE ONLY. These four people belong to Phoenix Park Village 1. Another estate
 * has its own committee and its own property office, and copying a default list across
 * the platform would be exactly the mistake the per-site table exists to prevent — so
 * a new estate starts with NO numbers, the platform records honestly that it texted
 * nobody, and somebody has to put the real ones in.
 */
class SiteEmergencyNumbersSeeder extends Seeder
{
    /** The estate the client's four numbers belong to. */
    public const ESTATE = 'phoenixpark';

    /**
     * The client's ruling, in the client's own order and the client's own characters.
     *
     * @var list<array{role: string, label: string, number: string}>
     */
    public const NUMBERS = [
        ['role' => 'president', 'label' => 'President', 'number' => '1-876-508-8082'],
        ['role' => 'property_office', 'label' => 'Property Office', 'number' => '1-876-224-4444'],
        ['role' => 'head_of_security', 'label' => 'PPV1 Head of Security', 'number' => '1-876-845-9422'],
        ['role' => 'dispatch', 'label' => 'Gemini Dispatch', 'number' => '876-354-2119'],
    ];

    public function run(): void
    {
        if (Tenant::query()->whereKey(self::ESTATE)->doesntExist()) {
            $this->command->warn('No estate ['.self::ESTATE.']: its emergency numbers were not seeded. Run estate:provision first.');

            return;
        }

        if (SiteEmergencyNumber::query()->where('tenant_id', self::ESTATE)->exists()) {
            $this->command->info('Phoenix Park Village 1 already has emergency numbers on file — left untouched.');

            return;
        }

        $numbers = app(SiteEmergencyNumbers::class);

        foreach (self::NUMBERS as $index => $row) {
            $numbers->put(
                tenantId: self::ESTATE,
                role: $row['role'],
                label: $row['label'],
                number: $row['number'],
                sort: $index + 1,
            );
        }

        $this->command->info('Phoenix Park Village 1: '.count(self::NUMBERS).' emergency numbers seeded for panic texts.');
    }
}
