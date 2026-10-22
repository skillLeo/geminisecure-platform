<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DuressAlert;
use App\Models\EstateAssignment;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Dispatch\AlertIntake;
use App\Support\SiteTime;
use Illuminate\Console\Command;

/**
 *   php artisan phoenix:classify-test-alerts --estate=phoenixparkvillage1             what it would classify
 *   php artisan phoenix:classify-test-alerts --estate=phoenixparkvillage1 --confirm   classify them
 *
 * THE TEST PRESSES FROM LOT 47, CLASSIFIED AS TESTS AND KEPT (DR-017, M62 B3; M73 §2.3). The panic button was tested
 * from the estate's own test household, and every press is still open: the red bar reads "6 emergencies need
 * attention" on every page of the console, and would on Sunday. Each open alert raised at that household is closed as
 * a dispatcher closes one, through `AlertIntake::resolve()`, classified "Test or drill" with a note saying why, so the
 * record, the audit entry and every console read it the ordinary way.
 *
 * NOTHING IS DELETED, EVER ("No panic or duress row is ever deleted. Not for any reason. Not ever."). An alert at any
 * other household, and one already closed, is not touched; the dry run lists both. Pushes and mail are off for this
 * command's own process: closing a test tells nobody.
 *
 * Acts as Gemini's Director (the first active one), who the audit entry names. Without --confirm nothing is written.
 * Run on production by a person who has read the dry run, never by a deploy.
 */
class PhoenixClassifyTestAlerts extends Command
{
    protected $signature = 'phoenix:classify-test-alerts
        {--estate=phoenixparkvillage1 : the estate}
        {--unit=Lot 47 : the household the tests were pressed from}
        {--confirm : classify them; without it nothing is written}';

    protected $description = 'Classify the test presses from the estate\'s test household as "Test or drill", deleting nothing (DR-017)';

    /** The classification, one of `AlertClassification::OPTIONS`' keys: "Test or drill", grouped "Not an incident". */
    public const CLASSIFICATION = 'drill';

    public const NOTE = 'Pressed while the estate tested the panic button from its own test household; classified as a test and kept on the record.';

    public function handle(AlertIntake $intake): int
    {
        $key = (string) $this->option('estate');
        $unit = trim((string) $this->option('unit'));
        $estate = $key === '' ? null : Tenant::query()->find($key);

        if (! $estate instanceof Tenant || $unit === '') {
            $this->error('Say which estate and household: --estate=phoenixparkvillage1 --unit="Lot 47". There is no such estate, or no household was named. Nothing was changed.');

            return self::INVALID;
        }

        $director = $this->director();

        if (! $director instanceof User) {
            $this->error('Gemini has no active Director to close the alerts as. Nothing was changed.');

            return self::INVALID;
        }

        $apply = (bool) $this->option('confirm');
        $atUnit = DuressAlert::query()->where('tenant_id', $key)->where('unit_reference', $unit)->orderBy('id')->get();
        $open = $atUnit->filter(static fn (DuressAlert $a): bool => $a->resolved_at === null && ! in_array($a->status, ['resolved', 'false_alarm', 'cancelled'], true) && ! $a->is_simulated)->values();
        $closed = $atUnit->count() - $open->count();

        $this->info(($apply ? 'Classifying the test presses at ' : 'What --confirm would classify at ').$estate->name.' ('.$key.'), '.$unit.($apply ? '' : '. Nothing is written without --confirm.'));
        $this->newLine();
        $this->table(['Alert', 'Raised (estate time)', 'Kind', 'Status now', 'After'], $open->map(static fn (DuressAlert $a): array => [
            '#'.$a->id,
            SiteTime::named($a->server_time, $key, 'M j, Y g:i A'),
            (string) $a->kind,
            (string) $a->status,
            'Resolved · Test or drill',
        ])->all());
        $this->line('NOT TOUCHED: any alert at another household; '.$closed.' alert'.($closed === 1 ? '' : 's').' at '.$unit.' already closed; nothing is ever deleted. Nobody is told.');
        $this->line('The note on each: "'.self::NOTE.'" Closed as: '.$director->name.' (Gemini Director).');

        if ($open->isEmpty()) {
            $this->newLine();
            $this->info('Nothing open to classify at '.$unit.'. Nothing was changed.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->newLine();
            $this->info($open->count().' alert'.($open->count() === 1 ? '' : 's').' would be classified as Test or drill. Nothing is written without --confirm.');

            return self::SUCCESS;
        }

        // Closing a test tells nobody: this process only.
        config(['push.enabled' => false, 'mail.default' => 'array']);

        foreach ($open as $alert) {
            $intake->resolve($alert, $director, self::NOTE, false, self::CLASSIFICATION);
        }

        $this->newLine();
        $this->info('Done: '.$open->count().' alert'.($open->count() === 1 ? '' : 's').' at '.$unit.' classified as Test or drill; none deleted.');

        return self::SUCCESS;
    }

    /** Gemini's Director, the first active one: who the audit entry names. */
    private function director(): ?User
    {
        $role = Role::query()->where('name', Role::DIRECTOR)->value('id');
        $assigned = $role === null ? null : User::query()->where('status', 'active')->whereHas('roles', static fn ($q) => $q->where('roles.id', $role))->orderBy('id')->first();

        return $assigned ?? ($role === null ? null : User::query()->whereKey(EstateAssignment::query()->where('role_id', $role)->where('is_active', true)->orderBy('id')->value('user_id'))->first());
    }
}
