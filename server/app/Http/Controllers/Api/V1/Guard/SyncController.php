<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Guard;

use App\Api\Catalogue;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\DispatchMessage;
use App\Models\DuressAlert;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\VisitorPass;
use App\Models\GuardRequest;
use App\Models\Shift;
use App\Services\Passes\PassSigningKeys;
use App\Support\SiteTime;
use Closure;
use Illuminate\Auth\RequestGuard;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The offline queue, uploaded and reconciled (13 D2).
 *
 * BATCH REPLAYS THE QUEUE THROUGH THE REAL ENDPOINTS. Each queued operation names
 * its catalogue endpoint and carries its own Idempotency-Key and its own device
 * time — the moment it happened, not the moment signal came back. The batch hands
 * each one to the HTTP kernel as its own request with the handset's own token, so
 * an operation synced later passes the same ability, validation, idempotency and
 * invariant checks it would have passed live, and a batch that is retried answers
 * each operation from its stored response instead of doing it twice.
 *
 * IN ORDER, AND IT STOPS AT A SERVER FAILURE. A clock-out queued after a clock-in
 * must not be attempted if the clock-in failed on the server; the remaining
 * operations come back `not_attempted` and the app sends them again. A refusal
 * (4xx) does not stop the batch: it is an answer, and the app shows it.
 *
 * PULL IS WHAT THE HANDSET MISSED, including the passes revoked while it was
 * offline — the list that turns an offline verdict into a reconciled one.
 */
class SyncController extends Controller
{
    public const MAX_OPERATIONS = 50;

    /** How long a resident's "happening now" is still happening now, for a handset that has been dark. */
    private const HAPPENING_NOW_HOURS = 12;

    /** How long an open alert is still news to a handset that pulls late — past this, dispatch has it in hand or it is stale. */
    private const OPEN_ALERT_MINUTES = 60;

    /**
     * HOW FAR BEHIND THE PULL ITS OWN CURSOR IS PUT (bug hunt B056).
     *
     * Every list below asks for `> since`, and every time it compares is stored to the
     * whole second. A pull that begins at 12:00:00.300 has run its queries by the time
     * a panic is raised at 12:00:00.800 — which is stored as 12:00:00. Had `next_since`
     * been 12:00:00, that panic would be behind the cursor of every pull from then on:
     * never delivered, to exactly the phone with no push token that the `alerts` list
     * is for. The same for a pass cancelled in that half-second, which the gate's
     * offline knowledge would then never learn of.
     *
     * So the cursor is put back past the second the pull ran in, and a little further,
     * because a row's time is taken by its writer before the row commits. The cost is
     * that what the last few seconds held comes again on the next pull, and the phone
     * keeps every list by id (`rememberAlerts`, `mergeRevoked`, the notifier's keys).
     * A cursor that re-asks is harmless; one that skips is a lost emergency.
     */
    private const CURSOR_OVERLAP_SECONDS = 10;

    /**
     * The three lists a long gap could make too long to send at once, and how many of
     * each one pull carries. When one is cut, the cursor stops where it was cut, so the
     * next pull carries on from there (bug hunt B063) — see `page()`.
     */
    private const MESSAGES_PER_PULL = 100;

    private const REPORTS_PER_PULL = 20;

    private const ALERTS_PER_PULL = 20;

    public function batch(Request $request, DeviceContext $context, HttpKernel $kernel): JsonResponse
    {
        $context->guardOrFail();

        $data = $request->validate([
            'operations' => ['required', 'array', 'min:1', 'max:'.self::MAX_OPERATIONS],
            'operations.*.id' => ['required', 'string', 'max:64'],
            'operations.*.endpoint' => ['required', 'string', 'max:64'],
            'operations.*.params' => ['nullable', 'array'],
            'operations.*.body' => ['nullable', 'array'],
            'operations.*.idempotency_key' => ['required', 'string', 'max:64'],
            'operations.*.device_time' => ['required', 'date'],
        ]);

        $endpoints = Catalogue::endpoints();
        $outer = $request;
        $results = [];
        $halted = false;
        $sanctum = Auth::guard('sanctum');

        foreach ($data['operations'] as $operation) {
            $name = $operation['endpoint'];
            $entry = $endpoints[$name] ?? null;

            if ($halted) {
                $results[] = $this->result($operation, 'not_attempted', null, false, null);

                continue;
            }

            if ($entry === null || ! self::batchable($name, $entry, $context->app)) {
                $results[] = $this->result($operation, 'refused', 422, false, ['error' => ['code' => 'endpoint_not_batchable', 'message' => 'Only the queued writes this app makes can be synced in a batch.']]);

                continue;
            }

            $uri = $this->uri($entry, (array) ($operation['params'] ?? []));

            if ($uri === null) {
                $results[] = $this->result($operation, 'refused', 422, false, ['error' => ['code' => 'params_invalid', 'message' => 'The operation\'s path parameters do not match its endpoint.']]);

                continue;
            }

            $inner = Request::create('http://localhost/api/v1/'.$uri, $entry['method'], [], [], [], [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
            ], (string) json_encode((object) ($operation['body'] ?? [])));

            $inner->headers->set('Authorization', (string) $outer->header('Authorization'));
            $inner->headers->set('Idempotency-Key', $operation['idempotency_key']);
            $inner->headers->set('X-Device-Time', Carbon::parse($operation['device_time'])->toIso8601String());

            if ($sanctum instanceof RequestGuard) {
                $sanctum->forgetUser();
                $sanctum->setRequest($inner);
            }

            try {
                $response = $kernel->handle($inner);
            } finally {
                if ($sanctum instanceof RequestGuard) {
                    $sanctum->forgetUser();
                    $sanctum->setRequest($outer);
                }

                app()->instance('request', $outer);
                app()->instance(DeviceContext::class, $context);
            }

            $status = $response->getStatusCode();
            $body = json_decode((string) $response->getContent(), true);
            $outcome = $status >= 500 ? 'failed' : ($status >= 400 ? 'refused' : 'ok');
            $halted = $status >= 500;

            $results[] = $this->result($operation, $outcome, $status, $response->headers->get('Idempotent-Replayed') === 'true', is_array($body) ? $body : null);
        }

        return response()->json([
            'results' => $results,
            'counts' => [
                'ok' => count(array_filter($results, static fn (array $r): bool => $r['outcome'] === 'ok')),
                'refused' => count(array_filter($results, static fn (array $r): bool => $r['outcome'] === 'refused')),
                'failed' => count(array_filter($results, static fn (array $r): bool => $r['outcome'] === 'failed')),
                'not_attempted' => count(array_filter($results, static fn (array $r): bool => $r['outcome'] === 'not_attempted')),
            ],
            'server_time' => $context->serverTime->toIso8601String(),
        ]);
    }

    public function pull(Request $request, DeviceContext $context, PassSigningKeys $keys): JsonResponse
    {
        $guard = $context->guardOrFail();

        $data = $request->validate(['since' => ['nullable', 'date']]);
        $since = isset($data['since']) ? SiteTime::parse((string) $data['since'], $context->tenantId) : Carbon::now()->subDays(7);
        $now = $context->serverTime;

        $shifts = Shift::query()->with(['post', 'estate'])
            ->where('guard_id', $guard->id)
            ->where('updated_at', '>', $since)
            ->where('rostered_end', '>=', $now->copy()->subDay())
            ->where('rostered_start', '<=', $now->copy()->addDays(14))
            ->orderBy('rostered_start')
            ->get()
            ->map(static fn (Shift $s): array => [
                'id' => $s->id,
                'status' => $s->status,
                // As the estate names the post, and "· patrol" for a patrol shift (M26, M27).
                'post' => ['id' => $s->post_id, 'name' => $s->postLabel()],
                'site' => ['id' => $s->tenant_id, 'name' => (string) ($s->estate->name ?? $s->tenant_id), 'timezone' => SiteTime::zone((string) $s->tenant_id)],
                'rostered_start' => SiteTime::iso($s->rostered_start),
                'rostered_end' => SiteTime::iso($s->rostered_end),
                'actual_start' => SiteTime::iso($s->actual_start),
                'actual_end' => SiteTime::iso($s->actual_end),
            ])->all();

        $orders = DB::connection('mysql')->table('standing_order_versions as v')
            ->join('standing_order_sets as s', static fn ($j) => $j->on('s.id', '=', 'v.standing_order_set_id')->on('s.version', '=', 'v.version'))
            ->where(static fn ($q) => $q->whereNull('s.post_id')->when($guard->post_id !== null, static fn ($w) => $w->orWhere('s.post_id', $guard->post_id)))
            ->where('v.created_at', '>', $since)
            ->get(['s.id as set_id', 'v.id as version_id', 'v.version', 'v.title', 'v.effective_on', 's.post_id'])
            ->map(static fn (object $o): array => [
                'set_id' => (int) $o->set_id,
                'version_id' => (int) $o->version_id,
                'version' => (int) $o->version,
                'title' => (string) $o->title,
                'effective_on' => (string) $o->effective_on,
                'requires_acknowledgement' => $o->post_id !== null,
            ])->all();

        // Where a capped list was cut, as the database holds that time (B063).
        $cuts = [];

        $messageQuery = static fn () => DB::connection('mysql')->table('dispatch_messages')
            ->where('tenant_id', $context->tenantId)
            ->where(static fn ($q) => $q->where('direction', DispatchMessage::BROADCAST)->orWhere('guard_id', $guard->id))
            ->where('direction', '!=', DispatchMessage::INBOUND)
            ->where('sent_at', '>', $since)
            ->orderBy('sent_at')
            ->orderBy('id');

        $messages = self::page(
            static fn (int $limit) => $messageQuery()->limit($limit)->get(),
            static fn (string $through) => $messageQuery()->where('sent_at', '<=', $through)->get(),
            static fn (object $m): string => (string) $m->sent_at,
            self::MESSAGES_PER_PULL,
            $cuts,
        )
            ->map(static fn (object $m): array => [
                'id' => (int) $m->id,
                'direction' => (string) $m->direction,
                'body' => (string) $m->body,
                'sent_at' => Carbon::parse((string) $m->sent_at)->toIso8601String(),
            ])->all();

        $checks = DB::connection('mysql')->table('alertness_checks')
            ->where('guard_id', $guard->id)->where('outcome', 'pending')->where('respond_by', '>=', $now)
            ->get()
            ->map(static fn (object $c): array => [
                'check_id' => (int) $c->id,
                'issued_at' => Carbon::parse((string) $c->issued_at)->toIso8601String(),
                'respond_by' => Carbon::parse((string) $c->respond_by)->toIso8601String(),
            ])->all();

        /*
         * IN THE ORDER THE PASSES WERE ISSUED, AND SAID SO (M11 A2). With no ORDER BY this list came
         * back in whatever order the plan read it: by id while the table was read whole, by expiry
         * once `visitor_passes_valid_to_index` reads only the passes still valid. The handset looks
         * each pass up by its `pass_id`, so nothing there depends on the order — but the payload stays
         * the payload it was, and with a year of passes MySQL still reads the index and sorts only the
         * few it found (48 rows read of 23,807, on a copy grown to a year — docs/perf/EXPLAIN.md).
         */
        $revoked = VisitorPass::query()
            ->where('valid_to', '>=', $now)
            ->where(static fn ($q) => $q
                ->where(static fn ($c) => $c->where('status', VisitorPass::CANCELLED)->where('cancelled_at', '>', $since))
                ->orWhere(static fn ($u) => $u->where('status', VisitorPass::USED)->where('single_use', true)->where('used_at', '>', $since)))
            ->orderBy('id')
            ->get()
            ->map(static fn (VisitorPass $p): array => [
                'pass_id' => $p->pass_id,
                'status' => $p->status,
                'at' => ($p->status === VisitorPass::CANCELLED ? $p->cancelled_at : $p->used_at)?->toIso8601String(),
            ])->all();

        $decisions = GuardRequest::query()->where('guard_id', $guard->id)->whereNotNull('decided_at')->where('decided_at', '>', $since)
            ->get()
            ->map(static fn (GuardRequest $r): array => [
                'id' => $r->id,
                'kind' => $r->kind,
                'status' => $r->status,
                'decided_at' => $r->decided_at?->toIso8601String(),
                'decision_note' => $r->decision_note,
            ])->all();

        $claims = DB::connection('mysql')->table('shift_claims')->where('guard_id', $guard->id)->whereNotNull('decided_at')->where('decided_at', '>', $since)
            ->get()
            ->map(static fn (object $c): array => [
                'claim_id' => (int) $c->id,
                'shift_id' => (int) $c->shift_id,
                'status' => (string) $c->status,
                'decided_at' => Carbon::parse((string) $c->decided_at)->toIso8601String(),
            ])->all();

        /*
         * WHAT A RESIDENT SAYS IS HAPPENING NOW (M4 §3.5).
         *
         * "Guards on duty at that site get the alert. The ticket itself still belongs
         * to community staff with source = resident. Notification is not ownership."
         * So this is a READ and nothing else — the handset is told, and neither this
         * query nor any endpoint it reaches lets a guard take, answer or close one.
         *
         * ONLY WHILE CLOCKED ON. Off duty, the list is empty: a guard at home is not
         * "on duty at that site", and telling them would be asking them to respond.
         * And only what is still current — a "happening now" from yesterday is not,
         * whatever `since` a handset that has been dark for a week sends.
         */
        $onDuty = Shift::query()
            ->where('tenant_id', $context->tenantId)
            ->where('guard_id', $guard->id)
            ->whereNotNull('actual_start')
            ->whereNull('actual_end')
            ->exists();

        $reportQuery = static fn () => MaintenanceTicket::query()
            ->where('happening_now', true)
            ->where('reported_at', '>', $since)
            ->where('reported_at', '>=', $now->copy()->subHours(self::HAPPENING_NOW_HOURS))
            ->orderBy('reported_at')
            ->orderBy('id');

        $reports = ! $onDuty ? [] : self::page(
            static fn (int $limit) => $reportQuery()->limit($limit)->get(),
            static fn (string $through) => $reportQuery()->where('reported_at', '<=', $through)->get(),
            static fn (MaintenanceTicket $t): string => (string) $t->getRawOriginal('reported_at'),
            self::REPORTS_PER_PULL,
            $cuts,
        )
            ->map(static fn (MaintenanceTicket $t): array => [
                'report_id' => (int) $t->id,
                'number' => (int) $t->number,
                'title' => (string) $t->title,
                'detail' => $t->description === null ? null : mb_substr($t->description, 0, 600),
                'location' => (string) $t->location_label,
                'latitude' => $t->latitude === null ? null : (float) $t->latitude,
                'longitude' => $t->longitude === null ? null : (float) $t->longitude,
                'accuracy_m' => $t->location_accuracy_m === null ? null : (int) $t->location_accuracy_m,
                'reported_at' => SiteTime::iso($t->reported_at),
            ])->all();

        /*
         * AN EMERGENCY AT THIS SITE, FOR A PHONE THE PUSH CANNOT REACH.
         *
         * A resident's panic or another officer's duress reaches the guards on duty by push
         * (`PushAnnouncer::alertRaised`). A handset with no push token — an Android build with
         * no Firebase project behind it, a token Expo refused — was reached by nothing at all,
         * and found out only if dispatch radioed. This is the same audience the push's first
         * tier has: on duty at this site, never the guard who raised it, never a simulated
         * alert. A read and nothing more — dispatch owns the alert — and it is never counted
         * in `alerted_handsets`, so what the resident is told stays the push's honest count.
         */
        $alertQuery = static fn () => DuressAlert::query()
            ->where('tenant_id', $context->tenantId)
            ->where('is_simulated', false)
            // A drill raised in drill mode wakes nobody (M53 §3A.1): no officer's phone is told of it, by push or by pull.
            ->where('drill_mode', false)
            ->whereNull('cancelled_at')
            ->whereNotIn('status', ['resolved', 'false_alarm'])
            ->where(static fn ($q) => $q->whereNull('guard_id')->orWhere('guard_id', '!=', $guard->id))
            ->where('server_time', '>', $since)
            ->where('server_time', '>=', $now->copy()->subMinutes(self::OPEN_ALERT_MINUTES))
            ->orderBy('server_time')
            ->orderBy('id');

        $alerts = ! $onDuty ? [] : self::page(
            static fn (int $limit) => $alertQuery()->limit($limit)->get(),
            static fn (string $through) => $alertQuery()->where('server_time', '<=', $through)->get(),
            static fn (DuressAlert $a): string => (string) $a->getRawOriginal('server_time'),
            self::ALERTS_PER_PULL,
            $cuts,
        )
            ->map(static fn (DuressAlert $a): array => [
                'alert_id' => (int) $a->id,
                'kind' => (string) $a->kind,
                'where' => $a->unit_reference === null || $a->unit_reference === '' ? null : (string) $a->unit_reference,
                'raised_at' => SiteTime::iso($a->server_time),

                // A drill (M10 §1.3) reaches a guard exactly as a panic does, so it says so.
                'is_drill' => (bool) $a->is_drill,
            ])->all();

        $current = $keys->currentVersion($context->tenantId);

        /*
         * THE CURSOR. Behind the second this pull ran in (B056), and never past the point
         * where a capped list was cut (B063): a list cut at its hundredth row carries on
         * from there on the next pull, instead of the rest of it falling behind a cursor
         * that had already jumped to now. Whatever else that re-asks for comes again, and
         * the phone keeps it by id.
         */
        $next = $now->copy()->startOfSecond()->subSeconds(self::CURSOR_OVERLAP_SECONDS);

        foreach ($cuts as $cut) {
            $at = Carbon::parse($cut, 'UTC');

            if ($at->lessThan($next)) {
                $next = $at;
            }
        }

        return response()->json([
            'since' => SiteTime::iso($since),
            'server_time' => $now->toIso8601String(),
            'next_since' => $next->toIso8601String(),

            // The guard's own site's zone — every time in this API is a UTC instant, shown in this (M4 §3.1).
            'timezone' => SiteTime::zone($context->tenantId),
            'shifts' => $shifts,
            'orders' => $orders,
            'messages' => $messages,
            'alertness_checks' => $checks,
            'passes' => [
                'revoked' => $revoked,
                'keys' => collect($keys->publicKeys($context->tenantId))
                    ->map(static fn (string $key, int $version): array => ['version' => $version, 'public_key' => $key, 'current' => $version === $current])
                    ->values()->all(),
            ],
            'requests' => $decisions,
            'claims' => $claims,
            'resident_reports' => $reports,
            'alerts' => $alerts,
        ]);
    }

    /**
     * One page of a capped list, oldest first — and where it was cut, when it was (B063).
     *
     * The list used to be the OLDEST so many rows, while the cursor moved to now: a phone
     * dark for three days was sent the hundred oldest of a hundred and fifty broadcasts,
     * and the fifty newest — the ones that mattered — fell behind the cursor for good.
     *
     * Now, when there is more than a page, the page stops after its last row, and that
     * row's time goes into `$cuts` so the cursor stops there too. It never stops INSIDE a
     * second: every row stored at the same second as the last one comes with the page,
     * because the next pull asks for what is after that second, and a row left behind in
     * it would be skipped — or, worse, a page all of one second would be asked for again
     * and again. So a page can run a little past the cap; it is never short of it.
     *
     * @template TRow
     *
     * @param  Closure(int): Collection<int, TRow>  $upTo  the list, oldest first, at most this many rows
     * @param  Closure(string): Collection<int, TRow>  $through  the list, oldest first, every row stored at or before this time
     * @param  Closure(TRow): string  $storedAt  a row's time exactly as the database holds it (`Y-m-d H:i:s`, UTC)
     * @param  list<string>  $cuts
     *
     * @param-out list<string>  $cuts
     *
     * @return Collection<int, TRow>
     */
    private static function page(Closure $upTo, Closure $through, Closure $storedAt, int $cap, array &$cuts): Collection
    {
        $rows = $upTo($cap + 1);

        if ($rows->count() <= $cap) {
            return $rows;
        }

        $cut = $storedAt($rows->values()->all()[$cap - 1]);
        $cuts[] = $cut;

        return $through($cut);
    }

    /**
     * A catalogue entry a queued operation may name: a write, for this app, that
     * is not itself a sync or an enrolment and takes no file.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function batchable(string $name, array $entry, string $app): bool
    {
        return $entry['write'] === true
            && in_array($app, $entry['apps'], true)
            && ! str_starts_with($name, 'sync.')
            && ($entry['batch'] ?? true) === true;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $params
     */
    private function uri(array $entry, array $params): ?string
    {
        $uri = (string) $entry['uri'];
        $where = (array) ($entry['where'] ?? []);

        $valid = true;

        $resolved = preg_replace_callback('/\{(\w+)\}/', static function (array $m) use ($params, $where, &$valid): string {
            $value = is_scalar($params[$m[1]] ?? null) ? (string) $params[$m[1]] : '';
            $pattern = $where[$m[1]] ?? '[^/]+';

            if ($value === '' || preg_match('#^'.$pattern.'$#', $value) !== 1) {
                $valid = false;
            }

            return rawurlencode($value);
        }, $uri);

        return $valid ? $resolved : null;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<mixed>|null  $body
     * @return array<string, mixed>
     */
    private function result(array $operation, string $outcome, ?int $status, bool $replayed, ?array $body): array
    {
        return [
            'id' => $operation['id'],
            'endpoint' => $operation['endpoint'],
            'outcome' => $outcome,
            'status' => $status,
            'replayed' => $replayed,
            'body' => $body,
        ];
    }
}
