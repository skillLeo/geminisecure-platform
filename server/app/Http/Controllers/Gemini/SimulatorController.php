<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Simulation\Simulator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * `/simulator` — the event simulator, Part C of the web deliverable.
 *
 * NOT ON ANY BOARD. It is a tool for whoever is reviewing the dispatch screens
 * before a handset exists, and it draws itself from the console's own shared
 * sheet so it reads as part of the console rather than as a developer page.
 *
 * ABSENT WHEN OFF. `routes/gemini/simulator.php` registers these routes only
 * while `GS_SIMULATOR_ENABLED` is set, so a host without the flag answers 404
 * — the same as for a URL nobody ever built. With the flag on, the routes sit
 * behind `gemini.platform_settings.run_simulator` (one of the three permissions
 * Platform settings' configure became in M11 B3), which the Director alone holds:
 * firing a panic alert into a client's dispatch queue is a platform act.
 *
 * EVERY PRESS GOES THROUGH /api/v1, and the results table shows what each
 * endpoint answered. See `Simulator` for why it never inserts a row.
 */
class SimulatorController extends Controller
{
    public function index(Request $request): Response
    {
        return inertia('Gemini/Simulator/Index', [
            'estates' => $this->estates(),
            'kinds' => Simulator::ALERT_KINDS,
            'arrivals' => array_map(
                static fn (array $arrival, int $index): array => [
                    'index' => $index,
                    'label' => ucfirst($arrival['verdict']).' — '.$arrival['category'].', '.strtolower($arrival['basis']),
                ],
                Simulator::ARRIVALS,
                array_keys(Simulator::ARRIVALS),
            ),
            'ambientMax' => Simulator::AMBIENT_MAX,

            // What the last press produced, carried over the redirect. A
            // fresh GET has nothing to show and says so.
            'results' => $request->session()->get('simulator.results', []),
            'summary' => $request->session()->get('simulator.summary'),
        ]);
    }

    /** Manual: one alert. */
    public function alert(Request $request, Simulator $simulator): RedirectResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'string', 'exists:tenants,id'],
            'kind' => ['required', 'string', 'in:'.implode(',', Simulator::ALERT_KINDS)],
            'offline' => ['nullable', 'boolean'],
        ]);

        $row = $simulator->alert(
            Tenant::query()->findOrFail($data['tenant_id']),
            $data['kind'],
            $request->boolean('offline'),
        );

        return $this->done([$row], 'One alert, through POST /api/v1/alerts.');
    }

    /** Manual: one arrival at the gate. */
    public function gateEvent(Request $request, Simulator $simulator): RedirectResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'string', 'exists:tenants,id'],
            'arrival' => ['required', 'integer', 'min:0', 'max:'.(count(Simulator::ARRIVALS) - 1)],
            'subject' => ['nullable', 'string', 'max:120'],
        ]);

        $row = $simulator->gateEvent(
            Tenant::query()->findOrFail($data['tenant_id']),
            Simulator::ARRIVALS[(int) $data['arrival']],
            isset($data['subject']) && trim($data['subject']) !== '' ? trim($data['subject']) : null,
        );

        return $this->done([$row], 'One arrival, through POST /api/v1/gate-events.');
    }

    /** Manual: a shift change at one estate. */
    public function shifts(Request $request, Simulator $simulator): RedirectResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'string', 'exists:tenants,id'],
        ]);

        $rows = $simulator->changeShifts(Tenant::query()->findOrFail($data['tenant_id']));

        return $this->done($rows, count($rows).' shift transition(s), through POST /api/v1/shifts/{shift}/clock-in and clock-out.');
    }

    /** Ambient: a seeded burst across every estate. */
    public function ambient(Request $request, Simulator $simulator): RedirectResponse
    {
        $data = $request->validate([
            'seed' => ['required', 'integer', 'min:0', 'max:999999'],
            'count' => ['required', 'integer', 'min:1', 'max:'.Simulator::AMBIENT_MAX],
        ]);

        $burst = $simulator->ambient(Tenant::estates(), (int) $data['seed'], (int) $data['count']);

        return $this->done($burst['rows'], sprintf(
            'Seed %d, %d event(s): %d new, %d replayed from an earlier run of the same seed.',
            (int) $data['seed'],
            count($burst['rows']),
            $burst['new'],
            $burst['replayed'],
        ));
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function estates(): array
    {
        return Tenant::estates()
            ->map(static fn (Tenant $estate): array => [
                'id' => (string) $estate->getTenantKey(),
                'name' => (string) $estate->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function done(array $rows, string $summary): RedirectResponse
    {
        return redirect()
            ->route('gemini.simulator')
            ->with('simulator.results', $rows)
            ->with('simulator.summary', $summary);
    }
}
