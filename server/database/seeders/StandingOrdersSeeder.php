<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The standing orders library - board screen super-admin-25.
 *
 * Five order sets: the company-wide general orders every guard is inducted on,
 * the emergency annex, and one post-specific set for each gate that has its
 * own written instructions.
 *
 * The acknowledgements are the point of the screen, so they are seeded against
 * the guard actually posted at each gate rather than scattered: Marcus Whyte
 * has signed for Phoenix Park's Main Gate, Kadeem Foster for Ocean View's, and
 * Phoenix Park's Service Gate has nobody who can sign at all - Devon Palmer is
 * posted there and his PSRA licence has lapsed, which makes him un-rosterable.
 * That last row is the one worth having in a demonstration dataset: an order
 * set in force at a post no one can legally stand.
 *
 * Idempotent. Every insert is keyed on the set's title, so running this twice
 * revises nothing and duplicates nothing.
 */
class StandingOrdersSeeder extends Seeder
{
    public function run(): void
    {
        $this->companyWide(
            'Company-wide General Orders',
            'general',
            'Applied to every post at every client',
            4,
            '2026-01-05',
            '2026-08-01',
            <<<'ORDERS'
            1. Report for duty in full uniform, with your licence and your bound device.
            2. Verify every visitor against a pass or a resident call-through. Where neither
               is available, apply the walk-up procedure and record the outcome.
            3. Never admit against a denied verdict without recording an override and the
               reason for it.
            4. A resident is never refused entry to their own estate over money owed.
            5. Log every equipment fault at the time you find it, not at the end of shift.
            ORDERS
        );

        $this->postSpecific('phoenixpark', 'Main Gate', 3, '2026-08-31', <<<'ORDERS'
            1. The barrier stays down between vehicles. No exceptions for a following car.
            2. Contractors are admitted on a pre-approved pass only, and are logged out.
            3. Phase 1 residents use the left lane; visitors use the right.
            4. Escalate a duress press immediately - do not attempt to verify it first.
            ORDERS);

        $this->postSpecific('phoenixpark', 'Service Gate', 2, '2026-04-14', <<<'ORDERS'
            1. Open for deliveries between 07:00 and 18:00 only.
            2. Every delivery vehicle's plate is recorded before the barrier is raised.
            3. The gate is locked and checked at the end of the day shift.
            ORDERS);

        $this->postSpecific('oceanview', 'Main Gate', 1, '2026-05-09', <<<'ORDERS'
            1. The estate is in onboarding: admit on the interim resident list until the
               pass system is live, and call through anything not on it.
            2. Record every admission on paper as well as on the device.
            ORDERS);

        $this->companyWide(
            'Emergency Procedures — Company-wide',
            'emergency',
            'Fire, medical, duress protocols',
            2,
            '2026-02-01',
            '2026-06-15',
            <<<'ORDERS'
            FIRE     Raise the alarm, open every barrier, do not attempt to fight it.
            MEDICAL  Call the ambulance first, then dispatch. Do not move the casualty.
            DURESS   Press and keep working normally. Dispatch will call you back on a
                     pretext; answering it clears the alert, silence escalates it.
            ORDERS
        );
    }

    /** An order set that applies at every post at every client. */
    private function companyWide(
        string $title,
        string $category,
        string $summary,
        int $version,
        string $effectiveOn,
        string $reviewedOn,
        string $body,
    ): void {
        $this->set([
            'title' => $title,
            'category' => $category,
            'summary' => $summary,
            'tenant_id' => null,
            'post_id' => null,
            'version' => $version,
            'body' => $body,
            'effective_on' => $effectiveOn,
            'reviewed_on' => $reviewedOn,
        ]);
    }

    /**
     * An order set attached to one post, acknowledged by whoever stands it.
     *
     * The acknowledgement is only written for a guard who could actually have
     * given it. A guard whose licence has lapsed is un-rosterable and has not
     * been standing the post, so seeding a tick for them would put a signature
     * in the record that never happened - and the library screen would report
     * a post as covered and current when it is neither.
     */
    private function postSpecific(string $tenantId, string $postName, int $version, string $effectiveOn, string $body): void
    {
        $post = DB::connection('mysql')
            ->table('posts')
            ->where('tenant_id', $tenantId)
            ->where('name', $postName)
            ->first();

        if ($post === null) {
            return;
        }

        $estate = DB::connection('mysql')->table('tenants')->where('id', $tenantId)->value('name');

        $id = $this->set([
            'title' => $estate.' — '.$postName,
            'category' => 'post_specific',
            'summary' => 'Post-specific orders',
            'tenant_id' => $tenantId,
            'post_id' => $post->id,
            'version' => $version,
            'body' => $body,
            'effective_on' => $effectiveOn,
            'reviewed_on' => null,
        ]);

        $guard = DB::connection('mysql')
            ->table('guards')
            ->where('post_id', $post->id)
            ->where('status', 'active')
            ->first();

        if ($guard === null) {
            return;
        }

        DB::connection('mysql')->table('standing_order_acknowledgements')->updateOrInsert(
            [
                'standing_order_set_id' => $id,
                'guard_id' => $guard->id,
                'version' => $version,
            ],
            [
                'acknowledged_at' => Carbon::parse($effectiveOn)->addDays(3),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Write one set, keyed on its title, and hand back its id.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function set(array $attributes): int
    {
        $title = $attributes['title'];
        unset($attributes['title']);

        DB::connection('mysql')->table('standing_order_sets')->updateOrInsert(
            ['title' => $title],
            $attributes + ['created_at' => now(), 'updated_at' => now()]
        );

        $id = (int) DB::connection('mysql')
            ->table('standing_order_sets')
            ->where('title', $title)
            ->value('id');

        /*
         * The version in force, kept whole (12 §2, item 28): an acknowledgement
         * is only worth the text it was given for. Only the current version is
         * seeded — earlier ones were never written down, and inventing their
         * text would be inventing what a guard was told.
         */
        DB::connection('mysql')->table('standing_order_versions')->updateOrInsert(
            ['standing_order_set_id' => $id, 'version' => $attributes['version']],
            [
                'title' => $title,
                'summary' => $attributes['summary'],
                'body' => $attributes['body'],
                'effective_on' => $attributes['effective_on'],
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return $id;
    }
}
