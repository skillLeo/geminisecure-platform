<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AlertnessCheck;
use App\Models\CheckpointScan;
use App\Models\DispatchMessage;
use App\Models\DuressAlert;
use App\Models\Guard;
use App\Models\GuardRequest;
use App\Models\PatrolCheckpoint;
use App\Models\Post;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Support\SiteTime;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Today's dispatch picture: rosters, patrol tours, alertness challenges,
 * inbound requests and the message log.
 *
 * The four dispatch boards are OPERATIONAL surfaces — who is on duty, which
 * post is empty, who has stopped scanning. An empty database renders every one
 * of them as a first-use empty state, which is a truthful screen and a useless
 * one: nobody can review a coverage board that has no coverage on it.
 *
 * DELIBERATELY IMPERFECT. Devon Palmer's licence has lapsed, so Phoenix Park's
 * Service Gate stands unmanned, and Andre Simpson has stopped scanning halfway
 * round his tour. A seed where every post is covered and every guard is awake
 * would leave the uncovered-post row and the missed-checkpoint banner permanently
 * unreachable — which is to say untested, on the two screens that exist for
 * exactly those cases.
 *
 * Everything device-originated is flagged is_simulated, so the console can say
 * plainly that a scan came from the seed rather than from a handset.
 */
class DispatchOperationsSeeder extends Seeder
{
    /** Where the day shift starts and the night shift takes over. */
    private const DAY_START = 7;

    private const NIGHT_START = 19;

    /**
     * Bound handsets, by employee number.
     *
     * Kadeem Foster is on his own phone. That is a real operational state — a
     * guard hired before the company had a spare device — and the Guard Profile
     * and alertness boards both distinguish it, so the seed has one.
     *
     * @var array<string, string>
     */
    private const DEVICES = [
        'GS-1041' => 'Company Pixel 7a',
        'GS-1052' => 'Company Pixel 7a',
        'GS-1070' => 'Personal device',
        'GS-1071' => 'Company Pixel 7a',
    ];

    /**
     * Patrol routes, by post name: the ordered checkpoints on the tour.
     *
     * @var array<string, list<string>>
     */
    private const ROUTES = [
        'Patrol - Phase 2-5' => [
            'Phase 2 gate house',
            'Phase 2 pump room',
            'Phase 3 playfield',
            'Phase 4 rear fence',
            'Phase 5 substation',
            'Phase 5 perimeter corner',
        ],
        'Patrol' => [
            'Pool deck',
            'Block A rear',
            'Generator room',
            'Beach gate',
            'Perimeter north',
        ],
    ];

    public function run(): void
    {
        if (Tenant::estates()->isEmpty()) {
            $this->command->warn('No estates provisioned; skipping dispatch operations seed.');

            return;
        }

        $this->bindDevices();
        $this->rosterToday();
        $this->buildPatrolRoutes();
        $this->recordPatrolTours();
        $this->recordAlertnessChallenges();
        $this->recordOneResolvedAlert();
        $this->raiseRequests();
        $this->writeMessages();
    }

    /* ------------------------------------------------------------- devices */

    private function bindDevices(): void
    {
        foreach (self::DEVICES as $employeeNumber => $label) {
            $guard = Guard::query()->where('employee_number', $employeeNumber)->first();

            if ($guard === null) {
                continue;
            }

            /*
             * The identifier is derived from the employee number rather than
             * random, so re-seeding rebinds the same handset instead of
             * tripping the unique index with a second one.
             */
            $guard->forceFill([
                'device_id' => 'DEV-'.str_replace('-', '', $employeeNumber),
                'device_label' => $label,
            ])->save();
        }
    }

    /* -------------------------------------------------------------- roster */

    /**
     * Two twelve-hour shifts per staffed post, plus last night's.
     *
     * A post is rostered only where a guard can actually stand it: active, and
     * holding a licence that has not lapsed. An expired licence is a hard block
     * rather than a warning, so Devon Palmer does not appear on a roster and
     * the Service Gate reads as uncovered — which is the true state of that
     * gate, and the row the coverage board exists to show.
     */
    private function rosterToday(): void
    {
        foreach (Post::query()->where('is_active', true)->get() as $post) {
            /*
             * ONE TIME RULE (M4 §3.1). 7 AM and 7 PM are hours ON THE CLOCK OF THE ESTATE THE POST
             * IS AT, so the day is built in that estate's zone — by name — and the model stores the
             * UTC moment each one means (07:00 Kingston is kept as 12:00 UTC). It used to be built
             * under UTC, which rostered every day shift for 2 AM Kingston.
             */
            $today = SiteTime::today((string) $post->tenant_id);

            $guard = Guard::query()
                ->where('post_id', $post->id)
                ->where('status', 'active')
                ->first();

            if ($guard === null || $guard->licenceState() === 'expired') {
                continue;
            }

            $windows = [
                // Last night, so a console opened before 7 AM still sees a
                // shift underway rather than an empty platform.
                [$today->copy()->subDay()->setHour(self::NIGHT_START), $today->copy()->setHour(self::DAY_START)],
                [$today->copy()->setHour(self::DAY_START), $today->copy()->setHour(self::NIGHT_START)],
                [$today->copy()->setHour(self::NIGHT_START), $today->copy()->addDay()->setHour(self::DAY_START)],
            ];

            foreach ($windows as [$start, $end]) {
                $underway = $start->lessThanOrEqualTo(now()) && $end->greaterThan(now());
                $finished = $end->lessThanOrEqualTo(now());

                Shift::updateOrCreate(
                    [
                        'guard_id' => $guard->id,
                        'post_id' => $post->id,
                        'rostered_start' => $start,
                    ],
                    [
                        'tenant_id' => $post->tenant_id,
                        'rostered_end' => $end,

                        /*
                         * Started a couple of minutes late, which is what a
                         * real handover looks like and what the actual-versus-
                         * rostered pair exists to record.
                         *
                         * NULL until the shift has actually begun. A shift
                         * rostered for tonight that already carries a start
                         * time is a lie the whole pair exists to prevent, and
                         * the activity feed would report a guard clocking in
                         * hours before they did.
                         */
                        'actual_start' => $start->greaterThan(now()) ? null : $start->copy()->addMinutes(2),
                        'actual_end' => $finished ? $end->copy()->subMinutes(3) : null,
                        'start_method' => $guard->deviceIsBound() ? 'biometric' : 'supervisor_pin',
                        'geofence_distance_m' => 12,
                        'mock_location_flag' => false,
                        'status' => match (true) {
                            $underway => 'active',
                            $finished => 'completed',
                            default => 'rostered',
                        },
                    ],
                );
            }
        }
    }

    /* -------------------------------------------------------------- patrol */

    private function buildPatrolRoutes(): void
    {
        foreach (Post::query()->where('type', 'patrol')->get() as $post) {
            $route = self::ROUTES[$post->name] ?? null;

            if ($route === null) {
                continue;
            }

            foreach ($route as $index => $label) {
                PatrolCheckpoint::updateOrCreate(
                    ['code' => strtoupper(substr($post->tenant_id, 0, 2)).'-CP-'.$post->id.'-'.($index + 1)],
                    [
                        'tenant_id' => $post->tenant_id,
                        'post_id' => $post->id,
                        'label' => $label,
                        'sequence' => $index + 1,
                        'is_active' => true,
                    ],
                );
            }
        }
    }

    /**
     * Two tours in progress, one of them going wrong.
     *
     * Renae Cross is four checkpoints into six and scanned eight minutes ago —
     * inside the cadence, nothing to say. Andre Simpson stopped after two of
     * five and has not scanned for forty-seven minutes, which is well past the
     * cadence and its grace period, so the monitoring board raises him.
     */
    private function recordPatrolTours(): void
    {
        $tours = [
            // employee number, checkpoints reached, minutes since the last one
            ['GS-1052', 4, 8],
            ['GS-1071', 2, 47],
        ];

        foreach ($tours as [$employeeNumber, $reached, $minutesAgo]) {
            $guard = Guard::query()->where('employee_number', $employeeNumber)->first();

            if ($guard === null || $guard->post_id === null) {
                continue;
            }

            $checkpoints = PatrolCheckpoint::query()
                ->where('post_id', $guard->post_id)
                ->orderBy('sequence')
                ->take($reached)
                ->get();

            foreach ($checkpoints as $index => $checkpoint) {
                // Walked in order, the earlier ones one cadence apart, so the
                // tour reads as a walk rather than a burst of scans.
                $at = now()->subMinutes($minutesAgo + ($reached - $index - 1) * PatrolCheckpoint::CADENCE_MINUTES);

                CheckpointScan::updateOrCreate(
                    ['idempotency_key' => 'seed-scan-'.$guard->id.'-'.$checkpoint->id],
                    [
                        'patrol_checkpoint_id' => $checkpoint->id,
                        'guard_id' => $guard->id,
                        'device_time' => $at,
                        'server_time' => $at,
                        'clock_skewed' => false,
                        'captured_offline' => false,
                        'is_simulated' => true,
                    ],
                );
            }
        }
    }

    /**
     * Alertness challenges for the static posts.
     *
     * A guard standing a gate is not walking a route, so there is no scan to
     * prove they are awake — the challenge is the only signal, which is why the
     * board draws the two columns as alternatives rather than as a pair.
     */
    private function recordAlertnessChallenges(): void
    {
        $challenges = [
            // employee number, minutes ago for the most recent, outcome
            ['GS-1041', 12, 'passed'],
            ['GS-1070', 3, 'passed'],
        ];

        foreach ($challenges as [$employeeNumber, $minutesAgo, $outcome]) {
            $guard = Guard::query()->where('employee_number', $employeeNumber)->first();

            if ($guard === null) {
                continue;
            }

            // A short history, not a single row: consecutive misses are a
            // count over a sequence, and one row cannot demonstrate a sequence.
            foreach ([$minutesAgo, $minutesAgo + 45, $minutesAgo + 90] as $offset) {
                $at = now()->subMinutes($offset);

                if (AlertnessCheck::query()->where('guard_id', $guard->id)->where('server_time', $at)->exists()) {
                    continue;
                }

                AlertnessCheck::create([
                    'guard_id' => $guard->id,
                    'outcome' => $outcome,
                    'device_time' => $at,
                    'server_time' => $at,
                    'is_simulated' => true,
                ]);
            }
        }
    }

    /**
     * One alert that was answered and closed.
     *
     * The live map reports the average time dispatch takes to acknowledge an
     * alert over thirty days. With every seeded alert still open that average
     * has nothing to average, and a life-safety response metric that renders
     * blank reads as a broken screen rather than as a quiet month.
     */
    private function recordOneResolvedAlert(): void
    {
        $estate = Tenant::estates()->first();

        if ($estate === null) {
            return;
        }

        $raisedAt = now()->subDays(2);

        DuressAlert::updateOrCreate(
            ['idempotency_key' => 'seed-resolved-panic-'.$estate->getTenantKey()],
            [
                'tenant_id' => $estate->getTenantKey(),
                'kind' => 'panic',
                'raised_by_name' => 'Rohan Peart',
                'unit_reference' => 'PP-2A',
                'status' => 'resolved',
                'device_time' => $raisedAt,
                'server_time' => $raisedAt,
                'acknowledged_by' => User::query()->where('email', 'director@geminisecurity.test')->value('id'),
                'acknowledged_at' => $raisedAt->copy()->addSeconds(14),
                'resolved_at' => $raisedAt->copy()->addMinutes(9),
                'resolution_note' => 'Resident confirmed a false alarm; gate checked and clear.',
                'is_simulated' => true,
            ],
        );
    }

    /* ------------------------------------------------------------ requests */

    private function raiseRequests(): void
    {
        $requests = [
            [
                'employee' => 'GS-1052',
                'kind' => GuardRequest::LEAVE,
                'subject' => 'Vacation',
                'starts_on' => now()->addDays(5)->toDateString(),
                'ends_on' => now()->addDays(12)->toDateString(),
                'certificate_attached' => false,
                'reason' => null,
                'quantity' => null,
            ],
            [
                'employee' => 'GS-1071',
                'kind' => GuardRequest::LEAVE,
                'subject' => 'Sick',
                'starts_on' => now()->subDays(5)->toDateString(),
                'ends_on' => now()->subDays(5)->toDateString(),
                'certificate_attached' => true,
                'reason' => null,
                'quantity' => null,
            ],
            [
                'employee' => 'GS-1070',
                'kind' => GuardRequest::EQUIPMENT,
                'subject' => 'Raincoat',
                'starts_on' => null,
                'ends_on' => null,
                'certificate_attached' => false,
                'reason' => 'worn out',
                'quantity' => 1,
            ],
        ];

        foreach ($requests as $row) {
            $guard = Guard::query()->where('employee_number', $row['employee'])->first();

            if ($guard === null) {
                continue;
            }

            GuardRequest::updateOrCreate(
                ['guard_id' => $guard->id, 'kind' => $row['kind'], 'subject' => $row['subject']],
                [
                    'tenant_id' => $guard->tenant_id ?? '',
                    'quantity' => $row['quantity'],
                    'starts_on' => $row['starts_on'],
                    'ends_on' => $row['ends_on'],
                    'reason' => $row['reason'],
                    'certificate_attached' => $row['certificate_attached'],
                    'status' => 'pending',
                ],
            );
        }
    }

    /* ------------------------------------------------------------ messages */

    private function writeMessages(): void
    {
        $dispatcher = User::query()->where('email', 'director@geminisecurity.test')->first();
        $phoenix = Tenant::find('phoenixpark') ?? Tenant::estates()->first();

        if ($phoenix === null) {
            return;
        }

        DispatchMessage::updateOrCreate(
            ['tenant_id' => $phoenix->getTenantKey(), 'direction' => DispatchMessage::BROADCAST, 'guard_id' => null],
            [
                'sent_by' => $dispatcher?->id,
                'body' => 'Service Gate remains uncovered — Main Gate and Patrol, keep an eye on that approach until relief is assigned.',
                'sent_at' => now()->startOfDay()->addHours(6)->addMinutes(15),
            ],
        );

        $marcus = Guard::query()->where('employee_number', 'GS-1041')->first();

        if ($marcus !== null) {
            DispatchMessage::updateOrCreate(
                ['tenant_id' => $marcus->tenant_id ?? '', 'direction' => DispatchMessage::INBOUND, 'guard_id' => $marcus->id],
                [
                    'body' => 'Barrier arm sensor fixed, confirming with a photo now.',
                    'sent_at' => now()->subDay()->startOfDay()->addHours(14)->addMinutes(40),
                ],
            );
        }
    }
}
