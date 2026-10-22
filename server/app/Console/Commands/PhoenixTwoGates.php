<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\Shift;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Gemini\SiteGeography;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 *   php artisan phoenix:two-gates --estate=phoenixparkvillage1             what it would change, changing nothing
 *   php artisan phoenix:two-gates --estate=phoenixparkvillage1 --confirm   change it
 *
 * PHOENIX PARK'S POSTS AS THE CLIENT DESCRIBES THEM (M26, M27; D-225). The client, 2 October 2026: "We only have two
 * gates… the main gate and the back gate, which is what we call gate number five or phase five gate… No gate is at
 * phase three." "Patrols will be done via a vehicle." "Their patrol would start at the main gate."
 *
 * The demonstration seeding had invented four posts and a gate position:
 *
 *   Phase 3 Pedestrian Gate   does not exist: removed (`SiteGeography::remove`: deleted, or retired if anything was
 *                             recorded at it; its un-started shifts go, its officers are left unposted)
 *   Perimeter Patrol          a geofenced post for a patrol that is a vehicle across five phases: its un-started shifts
 *                             become PATROL shifts at the Main Gate, where the patrol starts and is clocked in; its
 *                             checkpoints join the estate's own patrol route; the post itself is removed
 *   Main Gate                 kept; the position the seed invented for it (18.0243, -76.7896) is taken away, so it is
 *                             unsurveyed until somebody stands there. A position set in the console is left alone
 *   Back Gate                 kept, and also called "Gate 5, Phase 5 Gate"
 *
 * Idempotent: a second run finds nothing to change. Every change is audited with the reason below.
 */
class PhoenixTwoGates extends Command
{
    protected $signature = 'phoenix:two-gates
        {--estate= : the estate to correct (phoenixparkvillage1 on production)}
        {--confirm : make the changes; without it, only say what they would be}';

    protected $description = 'Correct an estate to the client\'s two gates and a vehicle patrol from the Main Gate (M26, M27)';

    /** The position `phoenix:demo-seed` gave the Main Gate before M26: Waterloo Road, to the nearest few hundred metres. */
    public const INVENTED_LATITUDE = 18.0243;

    public const INVENTED_LONGITUDE = -76.7896;

    public const BACK_GATE_ALSO = 'Gate 5, Phase 5 Gate';

    /** The demonstration's sample incident that named the gate the estate does not have, and what it says now. */
    public const INCIDENT_BEFORE = 'Pedestrian gate light out on the Phase 3 side. Reported to the property manager. Sample data.';

    public const INCIDENT_AFTER = 'Street light out on the Phase 3 road. Reported to the property manager. Sample data.';

    private const REASON = 'M26/M27: the client, 2 October 2026 — two gates, the Main Gate and the Back Gate (Gate 5, Phase 5 Gate); a vehicle patrol that starts at the Main Gate';

    public function handle(SiteGeography $geography, AuditLogger $audit): int
    {
        $key = (string) $this->option('estate');
        $estate = $key === '' ? null : Tenant::query()->find($key);

        if (! $estate instanceof Tenant) {
            $this->error('Say which estate: --estate=phoenixparkvillage1. There is no estate "'.$key.'".');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('confirm');
        $this->info(($apply ? 'Correcting ' : 'What would change at ').$estate->name.' ('.$key.')'.($apply ? '' : '. Nothing is changed without --confirm.'));

        $find = static fn (string $name): ?Post => Post::query()->where('tenant_id', $key)->where('is_active', true)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
        $changes = 0;

        // The two gates the estate has.
        $main = $find('Main Gate');
        $back = $find('Back Gate');

        foreach (['Main Gate' => $main, 'Back Gate' => $back] as $name => $gate) {
            if ($gate === null) {
                $this->line("  {$name}: missing — ".($apply ? 'added, with no position until somebody stands there' : 'would be added, with no position'));
                $changes++;

                if ($apply) {
                    $made = Post::query()->create(['tenant_id' => $key, 'name' => $name, 'type' => Post::GATE, 'is_active' => true, 'geofence_radius_m' => SiteGeography::MIN_RADIUS_M]);
                    $audit->record(action: 'site.post_added', entityType: 'Post', entityId: (string) $made->id, after: ['name' => $name, 'type' => Post::GATE, 'reason' => self::REASON], tenantId: $key);
                    $name === 'Main Gate' ? $main = $made : $back = $made;
                }
            }
        }

        // The Main Gate's invented position.
        if ($main !== null && $main->latitude !== null && round((float) $main->latitude, 4) === self::INVENTED_LATITUDE && round((float) $main->longitude, 4) === self::INVENTED_LONGITUDE) {
            $this->line('  Main Gate: the seed\'s invented position (18.0243, -76.7896) '.($apply ? 'taken away: unsurveyed until somebody stands there' : 'would be taken away'));
            $changes++;

            if ($apply) {
                $before = ['latitude' => $main->latitude, 'longitude' => $main->longitude, 'geofence_radius_m' => $main->geofence_radius_m];
                $main->forceFill(['latitude' => null, 'longitude' => null])->save();
                $audit->record(action: 'site.post_unplaced', entityType: 'Post', entityId: (string) $main->id, before: $before, after: ['latitude' => null, 'longitude' => null, 'reason' => self::REASON.'; the position was the demonstration seed\'s, never surveyed'], tenantId: $key);
            }
        } elseif ($main !== null && $main->latitude !== null) {
            $this->line('  Main Gate: placed at '.$main->latitude.', '.$main->longitude.' in the console, not by the seed — left as it is');
        }

        // The Back Gate's other names.
        if ($back !== null && $back->also_called !== self::BACK_GATE_ALSO) {
            $this->line('  Back Gate: '.($apply ? 'now' : 'would be').' also called "'.self::BACK_GATE_ALSO.'"');
            $changes++;

            if ($apply) {
                $before = $back->also_called;
                $back->forceFill(['also_called' => self::BACK_GATE_ALSO])->save();
                $audit->record(action: 'site.post_also_called', entityType: 'Post', entityId: (string) $back->id, before: ['also_called' => $before], after: ['also_called' => self::BACK_GATE_ALSO, 'reason' => self::REASON], tenantId: $key);
            }
        }

        // The patrol post: its future shifts become patrol shifts at the Main Gate, then it goes.
        $patrol = $find('Perimeter Patrol');

        if ($patrol !== null) {
            $future = Shift::query()->where('post_id', $patrol->id)->whereNull('actual_start')->count();
            $this->line('  Perimeter Patrol: '.($apply ? 'removed' : 'would be removed').'; '.$future.' shift'.($future === 1 ? '' : 's').' not yet started '.($apply ? 'moved' : 'would move').' to the Main Gate as patrol shifts');
            $changes++;

            if ($apply && $main !== null) {
                foreach (Shift::query()->where('post_id', $patrol->id)->whereNull('actual_start')->get() as $shift) {
                    $shift->forceFill(['post_id' => $main->id, 'duty' => Shift::DUTY_PATROL])->save();
                    $audit->record(action: 'roster.shift_moved', entityType: 'Shift', entityId: (string) $shift->id, before: ['post' => $patrol->name], after: ['post' => $main->name, 'duty' => Shift::DUTY_PATROL, 'reason' => self::REASON], tenantId: $key);
                }

                // Its officers patrol from the Main Gate now, so that is the post on their record.
                $moved = DB::connection('mysql')->table('guards')->where('post_id', $patrol->id)->update(['post_id' => $main->id, 'updated_at' => now()]);

                if ($moved > 0) {
                    $this->line('    '.$moved.' officer'.($moved === 1 ? '' : 's').' who had it as their post now '.($moved === 1 ? 'has' : 'have').' the Main Gate, where the patrol starts');
                }

                $this->line('    '.SiteGeography::removedSentence($geography->remove($patrol->refresh(), self::REASON)));
            }
        }

        // The gate that does not exist.
        $phase3 = $find('Phase 3 Pedestrian Gate');

        if ($phase3 !== null) {
            $this->line('  Phase 3 Pedestrian Gate: '.($apply ? 'removed — the estate has no gate at Phase 3' : 'would be removed'));
            $changes++;

            if ($apply) {
                $this->line('    '.SiteGeography::removedSentence($geography->remove($phase3, self::REASON)));
            }
        }

        // The demonstration's sample incident that named the Phase 3 gate: sample data, marked so, reworded.
        $incident = DB::connection('mysql')->table('security_incidents')->where('tenant_id', $key)->where('detail', self::INCIDENT_BEFORE)->where('is_simulated', true);

        if ((clone $incident)->exists()) {
            $this->line('  The sample incident about a "pedestrian gate on the Phase 3 side" '.($apply ? 'now reads: ' : 'would read: ').self::INCIDENT_AFTER);
            $changes++;

            if ($apply) {
                $incident->update(['detail' => self::INCIDENT_AFTER, 'updated_at' => now()]);
            }
        }

        $left = Post::query()->where('tenant_id', $key)->where('is_active', true)->orderBy('name')->get()
            ->map(static fn (Post $p): string => $p->label().($p->isPlaced() ? ' (placed)' : ' (not placed)'))->implode(', ');

        $this->newLine();
        $this->info($changes === 0 ? 'Nothing to change: '.$left.'.' : ($apply ? 'Done. Its posts now: '.$left.'.' : $changes.' change'.($changes === 1 ? '' : 's').' to make. Its posts now: '.$left.'.'));

        return self::SUCCESS;
    }
}
