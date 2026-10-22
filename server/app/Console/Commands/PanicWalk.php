<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DuressAlert;
use App\Models\DuressAlertDelivery;
use App\Models\Estate\Unit;
use App\Models\Tenant;
use App\Services\Dispatch\AlertIntake;
use App\Services\Dispatch\DeliveryReport;
use App\Services\Platform\OperatorEstates;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * WALK A PANIC END TO END, AND SAY WHO IT REACHED AND HOW FAST (M10 §10).
 *
 * "Walk a panic end to end and confirm every destination in §1.2 received it, with the
 * measured time to each." This is that walk, as a command anybody can run and read:
 *
 *   php artisan panic:walk demoestate --lot="Lot 1"
 *   php artisan panic:walk phoenixpark --lot="Lot 47" --force
 *
 * THE RESIDENT'S PATH, NOT A MODEL OF IT. The alert is raised through
 * `AlertIntake::record()` — the call `DuressController::store()` makes for a resident's
 * panic — with the estate open exactly as the API opens it, so the fan-out, the
 * `CentralQueue` pinning, the escalation mail, the texts and the live broadcast all run
 * the code a real press runs. Nothing is stubbed and nothing is predicted: what is
 * printed is read back from `duress_alert_deliveries` after the fact.
 *
 * IT IS A DRILL, AND IT SAYS SO EVERYWHERE IT LANDS. `is_drill` puts DRILL in front of
 * the dispatcher's queue row and response screen, the estate's pop-up, the bell, the
 * mail subject, the push title and the text — and keeps it out of every response-time
 * figure. It is NOT a simulated alert: a simulated one is sent to nobody on purpose, and
 * a walk that reached nobody would prove nothing.
 *
 * IT REFUSES WHERE IT WOULD WAKE SOMEBODY, UNLESS TOLD. On an estate that is not a
 * demonstration estate, and anywhere in production, it tells real guards, a real
 * committee and real Heads of Security — marked DRILL, but real phones and real inboxes.
 * So both need `--force`, and the refusal says why in a sentence. On the demonstration
 * estate the M5 §3 rules still hold — Gemini's consoles do not listen to it and nobody
 * real is mailed or texted — and the walk reports those destinations NOT REACHED with
 * that reason, which is the true answer there.
 *
 * HAND-OFF TIMES, NEVER ARRIVAL. Every figure is the platform letting go of a leg,
 * counted from the moment the platform began telling anyone (the live channel goes first). Legs on the central queue are waiting for a worker
 * when the walk prints, and it says so.
 *
 * THE DRILL IS LEFT OPEN, on purpose: the point of a walk is somebody looking at each
 * console and seeing it there. The last line says where to close it.
 */
class PanicWalk extends Command
{
    protected $signature = 'panic:walk
        {estate : the estate id, e.g. demoestate}
        {--lot= : the lot the drill is raised from, as the estate writes it, e.g. "Lot 47"}
        {--force : walk an estate that is not a demonstration estate, or run in production — real people are told, marked DRILL}';

    protected $description = 'Raise a DRILL panic through the resident\'s path and print who it reached, and how fast each leg was handed off.';

    /** Who a drill says raised it — the pop-up and the queue show this where a name goes. */
    public const RAISED_BY = 'Drill (panic:walk)';

    public function handle(AlertIntake $intake, DeliveryReport $report): int
    {
        $estateId = (string) $this->argument('estate');
        $force = (bool) $this->option('force');
        $lot = $this->option('lot');
        $lot = is_string($lot) && trim($lot) !== '' ? trim($lot) : null;

        $estate = Tenant::query()->find($estateId);

        if (! $estate instanceof Tenant) {
            $this->error('No estate ['.$estateId.'].');

            return self::FAILURE;
        }

        if (app()->isProduction() && ! $force) {
            $this->error('panic:walk will not run in production without --force. It raises a real alert, marked DRILL, and real people are told about it.');

            return self::FAILURE;
        }

        $demonstration = OperatorEstates::isDemonstration($estateId);

        if (! $demonstration && ! $force) {
            $this->error('['.$estateId.'] is not a demonstration estate. A walk here tells its real guards, committee and Heads of Security, marked DRILL. Pass --force if that is what you mean.');

            return self::FAILURE;
        }

        if ($force && (! $demonstration || app()->isProduction())) {
            $this->warn('--force: real people at ['.$estateId.'] will be told about this alert. Everything they receive says DRILL.');
        }

        try {
            /*
             * THE ESTATE OPEN, AS THE API OPENS IT. A resident's panic arrives with
             * tenancy initialised, so the estate's database is the default — which is
             * exactly the condition `CentralQueue` exists for. Walking it any other way
             * would prove a path no resident takes.
             */
            $alert = $estate->run(function () use ($intake, $estateId, $lot): ?DuressAlert {
                if ($lot !== null && Unit::query()->where('reference', $lot)->doesntExist()) {
                    return null;
                }

                $alert = $intake->record(
                    tenantId: $estateId,
                    kind: 'panic',
                    raisedByName: self::RAISED_BY,
                    unitReference: $lot,
                    deviceTime: now(),
                    idempotencyKey: 'panic-walk-'.Str::uuid()->toString(),
                    isDrill: true,

                    // The resident's path exactly: a press on the panic button.
                    fromPanicButton: true,
                );

                // What `DuressController::store()` writes next for a resident, so the row
                // is shaped exactly as a resident's is.
                $alert->forceFill(['mode' => 'silent', 'raised_by_account_id' => null])->save();

                return $alert;
            });
        } catch (Throwable $e) {
            $this->error('The drill could not be raised at ['.$estateId.']: '.class_basename($e).'. Nothing was sent.');

            return self::FAILURE;
        }

        if ($alert === null) {
            $this->error('No lot ['.$lot.'] at ['.$estateId.']. Give it as the estate writes it, e.g. "Lot 47" — or leave --lot out.');

            return self::FAILURE;
        }

        $alert->refresh();

        $this->line('');
        $this->line('<options=bold>DRILL #'.$alert->id.'</> raised at '.($estate->name ?? $estateId).' ['.$estateId.']'
            .($lot === null ? ', with no lot (as a panic from a resident with no unit would be)' : ', '.$lot)
            .', through the resident\'s path.');
        $this->line('');

        $walk = $report->forAlert($alert);

        if (! $walk['recorded']) {
            $this->error((string) $walk['empty_reason']);
        }

        $this->line('<options=bold>Who was told, and how fast</> — hand-off times, never arrival on a phone');

        $rows = [];

        foreach ($walk['destinations'] as $destination) {
            if ($destination['legs'] === []) {
                $rows[] = [$destination['label'], '—', '—', 'NOT REACHED', '—'];

                continue;
            }

            foreach ($destination['legs'] as $index => $leg) {
                $rows[] = [
                    $index === 0 ? $destination['label'] : '',
                    $leg['channel_label'],
                    $leg['recipients_label'],
                    $leg['outcome'],
                    $leg['reached'] ? $leg['elapsed_ms'].' ms' : $leg['elapsed_label'],
                ];
            }
        }

        $this->table(['Destination', 'Channel', 'Recipients', 'Outcome', 'elapsed_ms'], $rows);

        foreach ($walk['destinations'] as $destination) {
            if (! $destination['reached']) {
                $this->line('<fg=red>NOT REACHED</>  '.$destination['label'].' — '.$destination['reason']);
            }
        }

        $this->line('');
        $this->line($walk['reached'].' of '.$walk['of'].' destinations reached.'.($walk['fanout'] === null ? '' : ' '.$walk['fanout']));
        $this->line($walk['caption']);

        $queued = collect($walk['destinations'])->flatMap(static fn (array $d): array => $d['legs'])
            ->contains(static fn (array $leg): bool => $leg['outcome'] === DuressAlertDelivery::QUEUED);

        if ($queued) {
            $this->line('Legs marked `queued` are on the queue and go out when a worker takes them (php artisan queue:work --queue=push-urgent,default, as DEPLOY.md runs it).');
        }

        $this->line('The drill is still open, so it can be seen on each console. Close it at /dispatch/alerts/'.$alert->id.'.');

        return self::SUCCESS;
    }
}
