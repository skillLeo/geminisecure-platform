<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Estate\Amenity;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 *   php artisan amenities:design-terms --estate=phoenixparkvillage1             what it would change
 *   php artisan amenities:design-terms --estate=phoenixparkvillage1 --confirm   change it
 *
 * THE DESIGN'S FOUR AMENITIES, FIGURE FOR FIGURE (M47 §8.6; the user, 7 October: "The design's figures — neither set is
 * real client data, but the design's has to win here … the client's real prices go in later as data, not code"). Boards 21 and
 * 22 price by the hour, describe each place and book the Gazebo in two-hour blocks from 9 AM to 9 PM; the rate card on
 * the estate's server held a flat fee per booking and its own capacities. This sets the board's terms as data:
 *
 *   Community Centre   up to 120 · "kitchen & AV included"   $4,500/hr · $10,000 deposit
 *   Club House         up to 60  · "lounge & bar area"       $3,000/hr · $6,000 deposit
 *   Gazebo             up to 30  · "garden setting" · Phase 2 · $2,000/hr · $5,000 deposit · 9 AM – 9 PM · house rules
 *   Pool Deck          up to 40  · "shaded seating"          $2,500/hr · $5,000 deposit
 *
 * in that order, each booked in two-hour blocks. The flat fee stays where it was, unused while a rate is set, so setting
 * the rate back to nothing restores it. An amenity of another name is not touched; one of these names the estate does
 * not have is said, and not created.
 *
 * WHAT IT DOES NOT MOVE: any booking. A booking carries the terms it was made under (`Amenities::book`), so what is
 * already booked keeps its fee and deposit; only bookings made after this are priced by the hour.
 *
 * Without --confirm nothing is written. Run on production by a person who has read the dry run, never by a deploy.
 */
class AmenitiesDesignTerms extends Command
{
    protected $signature = 'amenities:design-terms
        {--estate= : the estate (phoenixparkvillage1 on production)}
        {--confirm : write the terms; without it nothing is written}';

    protected $description = 'Set the four amenities to the Resident App design\'s terms (M47 §8.6): by the hour, described, two-hour blocks';

    /** @var array<string, array<string, int|string|null>> */
    private const TERMS = [
        'Community Centre' => ['sort_order' => 1, 'capacity' => 120, 'description' => 'kitchen & AV included', 'place' => null, 'hourly_rate_minor' => 4_500_00, 'deposit_minor' => 10_000_00, 'slot_minutes' => 120],
        'Club House' => ['sort_order' => 2, 'capacity' => 60, 'description' => 'lounge & bar area', 'place' => null, 'hourly_rate_minor' => 3_000_00, 'deposit_minor' => 6_000_00, 'slot_minutes' => 120],
        'Gazebo' => [
            'sort_order' => 3, 'capacity' => 30, 'description' => 'garden setting', 'place' => 'Phase 2', 'hourly_rate_minor' => 2_000_00, 'deposit_minor' => 5_000_00, 'slot_minutes' => 120,
            'opens_at' => '09:00:00', 'closes_at' => '21:00:00',
            'rules_summary' => 'noise cut-off at 9:00 PM and no glassware on the lawn',
            'rules' => "Noise cut-off at 9:00 PM.\nNo glassware on the lawn.",
        ],
        'Pool Deck' => ['sort_order' => 4, 'capacity' => 40, 'description' => 'shaded seating', 'place' => null, 'hourly_rate_minor' => 2_500_00, 'deposit_minor' => 5_000_00, 'slot_minutes' => 120],
    ];

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
            $this->info(($apply ? 'Setting the design\'s amenity terms at ' : 'What --confirm would set at ').$estate->name.' ('.$key.')'.($apply ? '' : '. Nothing is written without --confirm.'));

            $byName = Amenity::query()->get()->keyBy(static fn (Amenity $a): string => mb_strtolower(trim($a->name)));
            $changes = [];
            $rows = [];

            foreach (self::TERMS as $name => $terms) {
                $amenity = $byName[mb_strtolower($name)] ?? null;

                if (! $amenity instanceof Amenity) {
                    $this->line('  '.$name.': the estate has no amenity of this name. It is not created; add it on the rate card first.');

                    continue;
                }

                foreach ($terms as $field => $after) {
                    $now = $amenity->getAttribute($field);

                    if ((string) $now !== (string) $after) {
                        $changes[$name][$field] = ['now' => $now, 'after' => $after];
                        $rows[] = [$name, $field, self::shown($field, $now), self::shown($field, $after)];
                    }
                }
            }

            if ($rows === []) {
                $this->newLine();
                $this->info('Every amenity already reads as the design does. Nothing was changed.');

                return self::SUCCESS;
            }

            $this->newLine();
            $this->table(['Amenity', 'Term', 'Now', 'After'], $rows);
            $this->line('NOT TOUCHED: any booking already made (each keeps the terms it was made under), any other amenity, the flat fee each keeps beside its rate.');

            if (! $apply) {
                $this->newLine();
                $this->info(count($rows).' term'.(count($rows) === 1 ? '' : 's').' would change. Nothing is written without --confirm.');

                return self::SUCCESS;
            }

            DB::connection('tenant')->transaction(function () use ($changes, $byName): void {
                foreach ($changes as $name => $fields) {
                    $byName[mb_strtolower($name)]->forceFill(array_map(static fn (array $c): int|string|null => $c['after'], $fields))->save();
                }
            });

            $audit->record(
                action: 'amenity.design_terms_set',
                entityType: 'Estate',
                entityId: $key,
                before: array_map(static fn (array $fields): array => array_map(static fn (array $c): mixed => $c['now'], $fields), $changes),
                after: array_map(static fn (array $fields): array => array_map(static fn (array $c): mixed => $c['after'], $fields), $changes),
                tenantId: $key,
            );

            $this->newLine();
            $this->info('Done: '.count($rows).' term'.(count($rows) === 1 ? '' : 's').' set on '.count($changes).' amenit'.(count($changes) === 1 ? 'y' : 'ies').'.');

            return self::SUCCESS;
        });
    }

    /** A term as a person reads it: money as "$2,000", nothing as "—". */
    private static function shown(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (str_ends_with($field, '_minor')) {
            return '$'.number_format(intdiv((int) $value, 100));
        }

        return str_replace("\n", ' / ', (string) $value);
    }
}
