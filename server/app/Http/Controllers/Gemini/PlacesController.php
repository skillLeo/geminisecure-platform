<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Models\PatrolCheckpoint;
use App\Models\Post;
use App\Services\Dispatch\ClockInFence;
use App\Services\Gemini\PlaceSearch;
use App\Services\Gemini\Schedule;
use App\Services\Gemini\SiteGeography;
use App\Support\DemoMode;
use App\Support\EstatePhone;
use App\Support\NewScreenWords;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Response;

/**
 * Places (M30 §7) — where a client's estate, gates and patrol are, at `/guards/places`, beside the classic Sites &
 * geofences (which is not touched).
 *
 * Every write goes through `SiteGeography`, the classic page's own service, so a position, a radius or a name is held
 * to the same rules and audited the same way, with who, when, and the position before and after. Only the reason
 * differs: the classic page asks why for every change (M12 §1.5); Places asks no reason (M30 §7.2, "setting up an
 * estate is not an action against a person"), so its audit entries say so in the reason itself.
 */
class PlacesController extends Controller
{
    /** What a change made here records as its reason: the truth, that none was asked. */
    public const REASON = 'Set on the Places screen, which asks no reason';

    /** The four steps along the top, in order (§7.2). */
    public const STEPS = ['estate', 'gates', 'patrol', 'settings'];

    /** A gate's clock-in circle on this screen (§7.2): from a gatehouse to a car park. */
    public const GATE_RADIUS = ['min' => SiteGeography::MIN_RADIUS_M, 'max' => 200, 'default' => 50];

    /** How far out the estate's own area reaches. */
    public const ESTATE_RADIUS = ['min' => 50, 'max' => SiteGeography::MAX_RADIUS_M, 'default' => 400];

    /** Where the map opens when nothing at the client is placed yet (§7.3): Kingston, at a city's zoom. */
    public const KINGSTON = ['lat' => 17.9970, 'lng' => -76.7936, 'zoom' => 12];

    public function show(Request $request, Schedule $schedule, SiteGeography $geography): Response
    {
        $viewer = $request->user();
        $clients = $schedule->clients($viewer);
        $asked = $request->query('client');
        $chosen = $schedule->choose($clients, is_string($asked) && $asked !== '' ? $asked : null);
        $step = in_array($request->query('step'), self::STEPS, true) ? (string) $request->query('step') : 'estate';
        $shared = [
            'clients' => $clients,
            'step' => $step,
            'canWrite' => $viewer->can('gemini.guard_workforce.update'),
            'limits' => ['gate' => self::GATE_RADIUS, 'estate' => self::ESTATE_RADIUS],
            'kingston' => self::KINGSTON,
            // "How clock in works", in the classic page's own words and numbers (§7.2 step 4: unchanged in wording).
            'fenceRule' => ['good_accuracy_m' => ClockInFence::GOOD_ACCURACY_M, 'poor_accuracy_m' => ClockInFence::POOR_ACCURACY_M, 'max_margin_m' => ClockInFence::MAX_MARGIN_M, 'margin_per_accuracy' => ClockInFence::MARGIN_PER_ACCURACY],
            /*
             * WHAT A GATE WITH NO LOCATION MEANS ON THIS SERVER (§9's second line, said truly): with no fence at all a
             * demonstration server starts the shift and marks it for the office; a live one refuses it and offers the
             * supervisor's PIN (ClockInFence::judge, M16 §4).
             */
            'unfencedStarts' => DemoMode::enabled(),
        ];

        if ($chosen === null) {
            return inertia('Gemini/Operations/Places', [...$shared, 'client' => null]);
        }

        $board = $geography->board($viewer, $chosen['id']);
        $tenant = DB::connection('mysql')->table('tenants')->where('id', $chosen['id'])->first(['address_line', 'parish']);
        $where = implode(', ', array_filter([trim((string) ($tenant->address_line ?? '')), trim((string) ($tenant->parish ?? ''))]));

        return inertia('Gemini/Operations/Places', [
            ...$shared,
            'client' => $chosen,
            'estate' => [...($board['site'] ?? []), 'name' => $chosen['name'], 'where' => $where !== '' ? $where : null],
            // Step 2 is the gates (and reliefs); the older patrol-route posts are the patrol's, in step 3.
            'gates' => array_values(array_filter($board['posts'], static fn (array $p): bool => $p['type'] !== Post::PATROL)),
            'patrolPosts' => array_values(array_filter($board['posts'], static fn (array $p): bool => $p['type'] === Post::PATROL)),
            'checkpoints' => self::markSamples($board['checkpoints'], $chosen['id']),
            'phases' => $board['phases'] ?? [],
            'patrolInterval' => $board['patrolInterval'] ?? null,
            'patrolStart' => SiteGeography::patrolStart($chosen['id']),
            'estateSeesGuards' => $board['estateSeesGuards'],

            // M32 F7: the supervisor's number box shows this estate's own example, not the platform's home's.
            'phoneExample' => EstatePhone::example($chosen['id']),
        ]);
    }

    /** Step 3: the gate the patrol starts at, or none. */
    public function patrolStart(Request $request, string $client, SiteGeography $geography): RedirectResponse
    {
        $in = $request->validate(['post_id' => ['nullable', 'integer']]);
        $postId = isset($in['post_id']) ? (int) $in['post_id'] : null;

        return $this->done(fn () => $geography->setPatrolStart($request->user(), $client, $postId, self::REASON), $postId === null ? 'The patrol has no start set.' : 'Saved: the patrol starts at '.Post::query()->whereKey($postId)->value('name').'.');
    }

    /** Step 3: how long after one checkpoint the next is due; empty puts the platform's own back. */
    public function patrolInterval(Request $request, string $client, SiteGeography $geography): RedirectResponse
    {
        $in = $request->validate(['minutes' => ['nullable', 'integer']]);

        return $this->done(fn () => $geography->setPatrolInterval($request->user(), $client, isset($in['minutes']) ? (int) $in['minutes'] : null, self::REASON), 'Saved: how long after one checkpoint the next is due.');
    }

    /** Step 3: one checkpoint on the estate's route, by name (and its phase), placed afterwards. */
    public function addCheckpoint(Request $request, string $client, SiteGeography $geography): RedirectResponse
    {
        $in = $request->validate(['label' => ['required', 'string', 'max:120'], 'phase' => ['nullable', 'string', 'max:32']]);

        return $this->done(
            fn () => $geography->addRouteCheckpoint($request->user(), $client, (string) $in['label'], $in['phase'] ?? null, null, null, PatrolCheckpoint::DEFAULT_RADIUS_M, self::REASON),
            trim((string) $in['label']).' is on the route. Set its location when its tag is up.',
        );
    }

    /** Step 3: many checkpoints at once, one name to a line, each optionally led by its phase. */
    public function addCheckpoints(Request $request, string $client, SiteGeography $geography): RedirectResponse
    {
        $in = $request->validate(['lines' => ['required', 'string', 'max:6000']]);
        $made = 0;

        return $this->done(function () use ($request, $geography, $client, $in, &$made): void {
            $made = count($geography->addRouteCheckpoints($request->user(), $client, (string) $in['lines'], self::REASON));
        }, 'Checkpoints added to the route.');
    }

    /**
     * Step 3: many checkpoints placed at once, from the installers' messages as the page matched them and the person
     * confirmed them (M27 §4.3, the coordinate route; D-225). The same rules as the classic page's.
     */
    public function placeCheckpoints(Request $request, string $client, SiteGeography $geography): RedirectResponse
    {
        $in = $request->validate([
            'places' => ['required', 'array', 'max:'.SiteGeography::MAX_CHECKPOINTS_AT_ONCE],
            'places.*.id' => ['required', 'integer'],
            'places.*.lat' => ['required', 'numeric'],
            'places.*.lng' => ['required', 'numeric'],
        ]);
        $places = array_map(static fn (array $p): array => ['id' => (int) $p['id'], 'lat' => (float) $p['lat'], 'lng' => (float) $p['lng']], array_values($in['places']));
        $count = count($places);

        return $this->done(fn () => $geography->placeRouteCheckpoints($request->user(), $client, $places, self::REASON), ($count === 1 ? '1 checkpoint now has its location' : $count.' checkpoints now have their locations').', where their tags hang.');
    }

    /** Step 3: a checkpoint placed where its tag hangs. */
    public function placeCheckpoint(Request $request, int $checkpoint, SiteGeography $geography): RedirectResponse
    {
        $in = $request->validate(['lat' => ['required', 'numeric'], 'lng' => ['required', 'numeric']]);

        return $this->done(fn () => $geography->placeCheckpoint($request->user(), $checkpoint, (float) $in['lat'], (float) $in['lng']), 'Its location is saved.');
    }

    /** Step 3: the route in a new order, as it was dragged in the list. */
    public function reorder(Request $request, string $client, SiteGeography $geography): RedirectResponse
    {
        $in = $request->validate(['order' => ['required', 'array', 'max:'.SiteGeography::MAX_CHECKPOINTS_AT_ONCE * 4], 'order.*' => ['integer']]);

        return $this->done(fn () => $geography->reorderRoute($request->user(), $client, array_values($in['order'])), 'The route\'s new order is saved. Print the tags again if their numbers matter on the wall.');
    }

    /** Step 3: a checkpoint taken off the route; its scans stay on the record under its name. */
    public function retireCheckpoint(Request $request, int $checkpoint, SiteGeography $geography): RedirectResponse
    {
        return $this->done(fn () => $geography->retireCheckpoint($request->user(), $checkpoint, self::REASON), 'It is off the route. Its scans stay on the record.');
    }

    /** Up to five places for what was typed above the map; an empty list and one line when search cannot answer. */
    public function search(Request $request, PlaceSearch $places): JsonResponse
    {
        return response()->json($places->search((string) $request->validate(['q' => ['required', 'string', 'max:200']])['q']));
    }

    /** Step 1: the estate's own centre and how far out it reaches. Its supervisor's number is kept as it is. */
    public function placeEstate(Request $request, string $client, SiteGeography $geography): RedirectResponse
    {
        $in = $request->validate(['lat' => ['required', 'numeric'], 'lng' => ['required', 'numeric'], 'radius_m' => ['required', 'integer']]);
        $phone = DB::connection('mysql')->table('estate_sites')->where('tenant_id', $client)->value('supervisor_phone');

        return $this->done(fn () => $geography->placeEstate($request->user(), $client, [
            'lat' => (float) $in['lat'],
            'lng' => (float) $in['lng'],
            'radius_m' => (int) $in['radius_m'],
            'supervisor_phone' => is_string($phone) ? $phone : null,
        ], self::REASON), 'The estate\'s area is saved.');
    }

    /** Step 2: a new gate, with only its name asked, placed where the map was clicked. */
    public function addGate(Request $request, string $client, SiteGeography $geography): RedirectResponse
    {
        $in = $request->validate(['name' => ['required', 'string', 'max:120'], 'lat' => ['required', 'numeric'], 'lng' => ['required', 'numeric'], 'radius_m' => ['required', 'integer']]);

        return $this->done(
            fn () => $geography->addPost($request->user(), $client, (string) $in['name'], 'gate', (float) $in['lat'], (float) $in['lng'], (int) $in['radius_m'], self::REASON),
            trim((string) $in['name']).' is added, with its location.',
        );
    }

    /** Step 2: a gate's location and how close a guard must be to clock in there. */
    public function placeGate(Request $request, int $post, SiteGeography $geography): RedirectResponse
    {
        $in = $request->validate(['lat' => ['required', 'numeric'], 'lng' => ['required', 'numeric'], 'radius_m' => ['required', 'integer']]);
        $name = (string) Post::query()->whereKey($post)->value('name');

        return $this->done(
            fn () => $geography->placePost($request->user(), $post, (float) $in['lat'], (float) $in['lng'], (int) $in['radius_m'], self::REASON),
            ($name !== '' ? $name.'\'s' : 'The gate\'s').' location is saved.',
        );
    }

    /** Step 2: a gate's name, and the other names the estate uses for it. Only what changed is written. */
    public function renameGate(Request $request, int $post, SiteGeography $geography): RedirectResponse
    {
        $in = $request->validate(['name' => ['required', 'string', 'max:120'], 'also_called' => ['nullable', 'string', 'max:'.Post::ALSO_CALLED_MAX]]);
        $current = Post::query()->whereKey($post)->where('is_active', true)->first(['id', 'name', 'also_called']);
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $in['name']));
        $also = trim((string) preg_replace('/\s+/u', ' ', (string) ($in['also_called'] ?? '')));

        return $this->done(function () use ($request, $geography, $post, $current, $name, $also): void {
            $renamed = $current !== null && $name !== $current->name;
            $recalled = $current !== null && $also !== (string) ($current->also_called ?? '');

            if ($current !== null && ! $renamed && ! $recalled) {
                throw new DomainException('Nothing has changed. Change the name or the other names, then save.');
            }

            DB::connection('mysql')->transaction(function () use ($request, $geography, $post, $name, $also, $renamed, $recalled, $current): void {
                if ($current === null || $renamed) {
                    $geography->renamePost($request->user(), $post, $name, self::REASON);
                }

                if ($recalled) {
                    $geography->setAlsoCalled($request->user(), $post, $also === '' ? null : $also, self::REASON);
                }
            });
        }, 'Saved: '.$name.'.');
    }

    /** Step 4: the number a guard at the gate calls for a supervisor. The estate's area is kept as it is. */
    public function supervisor(Request $request, string $client, SiteGeography $geography): RedirectResponse
    {
        $in = $request->validate(['phone' => ['nullable', 'string', 'max:32']]);
        $site = DB::connection('mysql')->table('estate_sites')->where('tenant_id', $client)->first();

        return $this->done(fn () => $geography->placeEstate($request->user(), $client, [
            'lat' => $site?->latitude === null ? null : (float) $site->latitude,
            'lng' => $site?->longitude === null ? null : (float) $site->longitude,
            'radius_m' => $site?->geofence_radius_m === null ? null : (int) $site->geofence_radius_m,
            'supervisor_phone' => is_string($in['phone'] ?? null) ? $in['phone'] : null,
            // M32 F7: a refused number is answered with this estate's own example.
        ], self::REASON, EstatePhone::forEstate($client)['local']), trim((string) ($in['phone'] ?? '')) === '' ? 'The supervisor\'s number is taken off.' : 'The supervisor\'s number is saved.');
    }

    /** Step 4: whether this client's own console sees its guards' positions while they are on duty (TRACK-01). */
    public function guardPositions(Request $request, string $client, SiteGeography $geography): RedirectResponse
    {
        $sees = (bool) $request->validate(['sees' => ['required', 'boolean']])['sees'];

        // Said truthfully while positions are held from every estate console (M57 §9.1): the switch is kept, and changes nothing yet.
        $held = (bool) config('tracking.estate_positions_held', true);

        return $this->done(fn () => $geography->setEstateSeesGuards($request->user(), $client, $sees), match (true) {
            $sees && $held => 'Saved. No estate console shows an officer\'s position yet: positions are held from every estate until that is confirmed with the client.',
            $sees => 'This client now sees its guards on the map while they are clocked in.',
            default => 'This client no longer sees where its guards stand.',
        });
    }

    /**
     * The checkpoints a demonstration seed made, marked (§11), so nobody takes one for the client's own: by the id
     * ranges the seed recorded (`SampleData`). Gates are never marked: posts are the estate's structure, kept by
     * every purge, and the one the seed made first the client confirmed is real (D-225). Nothing is deleted here.
     *
     * @param  list<array<string, mixed>>  $checkpoints
     * @return list<array<string, mixed>>
     */
    private static function markSamples(array $checkpoints, string $client): array
    {
        $ranges = DB::connection('mysql')->table('sample_data_ranges')
            ->where('tenant_id', $client)->where('connection', 'mysql')->where('table_name', 'patrol_checkpoints')
            ->get(['from_id', 'to_id']);

        return array_map(static fn (array $c): array => [
            ...$c,
            'sample' => $ranges->contains(static fn (object $r): bool => $c['id'] > (int) $r->from_id && $c['id'] <= (int) $r->to_id),
        ], $checkpoints);
    }

    /** Run a write; a refusal comes back in this screen's words, for the screen to show in one line. */
    private function done(callable $write, string $said): RedirectResponse
    {
        try {
            $write();
        } catch (DomainException $refused) {
            return back()->withErrors(['places' => NewScreenWords::say($refused->getMessage())]);
        }

        return back()->with('success', $said);
    }
}
