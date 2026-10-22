<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Guard;
use App\Models\Post;
use App\Models\Tenant;
use App\Support\Seeded\ReservedContacts;
use Illuminate\Database\Seeder;

/**
 * Guards and posts, mirroring the approved wireframe's roster.
 *
 * Includes Devon Palmer with a lapsed PSRA licence deliberately. The compliance
 * screen exists for exactly that case, and a seed where every licence is valid
 * would leave it permanently empty and untested.
 */
class GuardWorkforceSeeder extends Seeder
{
    /**
     * [name, employee no, psra, employment, status, post, licence offset, estate]
     *
     * The estate is stated, not round-robined. The boards put specific guards
     * at specific clients — Client Detail draws Marcus Whyte, Renae Cross and
     * Devon Palmer under Phoenix Park's "Guards deployed" — and spreading the
     * roster by loop index put two of those three at the wrong estate, so the
     * panel came up a row short against its board.
     *
     * A guard with no estate named falls to the first provisioned one.
     */
    private const ROSTER = [
        ['Marcus Whyte', 'GS-1041', 'PSRA-004471', 'full_time', 'active', 'Main Gate', 210, 'phoenixpark'],
        ['Renae Cross', 'GS-1052', 'PSRA-004512', 'full_time', 'active', 'Patrol - Phase 2-5', 168, 'phoenixpark'],
        ['Devon Palmer', 'GS-1049', 'PSRA-004498', 'full_time', 'licence_expired', 'Service Gate', -14, 'phoenixpark'],
        ['Marlon Bailey', 'GS-1063', 'PSRA-004633', 'part_time', 'on_leave', 'Main Gate - relief', 96, 'phoenixpark'],
        ['Kadeem Foster', 'GS-1070', 'PSRA-004701', 'full_time', 'active', 'Main Gate', 21, 'oceanview'],
        ['Andre Simpson', 'GS-1071', 'PSRA-004715', 'full_time', 'active', 'Patrol', 289, 'oceanview'],
    ];

    public function run(): void
    {
        $estates = Tenant::estates();

        if ($estates->isEmpty()) {
            $this->command->warn('No estates provisioned; skipping guard workforce seed.');

            return;
        }

        foreach (self::ROSTER as $i => [$name, $employeeNo, $psra, $type, $status, $postName, $offset, $subdomain]) {
            // Where the board puts them. Falling back to the first provisioned
            // estate keeps the seed working on an installation that has never
            // provisioned the two the boards are drawn from.
            $estate = $estates->first(fn (Tenant $t): bool => $t->getTenantKey() === $subdomain) ?? $estates->first();

            $post = Post::firstOrCreate(
                ['tenant_id' => $estate->getTenantKey(), 'name' => $postName],
                ['type' => str_contains(strtolower($postName), 'patrol') ? 'patrol' : 'gate'],
            );

            Guard::updateOrCreate(
                ['employee_number' => $employeeNo],
                [
                    'full_name' => $name,
                    'psra_number' => $psra,
                    'psra_expires_on' => now()->addDays($offset)->toDateString(),
                    'employment_type' => $type,
                    'status' => $status,
                    'phone' => ReservedContacts::seededPhone(2000 + $i),
                    'email' => str($name)->lower()->replace(' ', '.')->append('@geminisecurity.test')->value(),
                    'hired_on' => now()->subMonths(8 + $i)->toDateString(),
                    'tenant_id' => $estate->getTenantKey(),
                    'post_id' => $post->id,
                ],
            );
        }
    }
}
