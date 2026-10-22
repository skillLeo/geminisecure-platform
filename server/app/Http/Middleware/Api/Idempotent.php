<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use App\Api\ApiError;
use App\Api\IdempotencyStore;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * A retried write is answered, not repeated (13 D2).
 *
 *   idempotent            `Idempotency-Key` is required
 *   idempotent:optional   honoured when sent (the first seven endpoints)
 *   idempotent:opaque     required, and the stored hash covers the method and path
 *                         only — for the ballot, whose body must leave no trace
 *                         beside the account that sent it (13 D3)
 *
 * A HANDSET AT A GATE LOSES SIGNAL CONSTANTLY, and the one thing worse than a
 * request that failed is one that succeeded and whose answer was lost — the app
 * sends it again. The key is chosen by the app BEFORE the first attempt and
 * reused on every retry. The first attempt's response is stored against the
 * handset and the key; every retry gets that same response back, byte for byte,
 * with `Idempotent-Replayed: true`, and nothing happens twice.
 *
 *   same key, same request        the stored response, replayed
 *   same key, different request   422 `idempotency_key_reused` — a bug in the app
 *   same key, another estate      422 `idempotency_key_reused` — see below
 *   same key, first still running 409 `request_in_progress` — wait and retry
 *   same key, first attempt died  409 `idempotent_answer_missing` — final; a panic or
 *                                 duress is run again instead (see takeOverAbandoned)
 *
 * A 5xx is not stored: the server failed, and the retry deserves a real attempt.
 *
 * WHERE THE KEY LIVES AND WHERE THE ANSWER LIVES ARE TWO DIFFERENT QUESTIONS.
 * The key is central, because it must be unique across the whole platform and the
 * "still running" lock must be one lock. The ANSWER is this estate's own content,
 * and it is kept in this estate's own database — `App\Api\IdempotencyStore` holds
 * that decision and the reasoning behind it. Nothing about the promise above
 * changed: this middleware stores the same response and replays the same bytes.
 *
 * AND THE ESTATE IS CHECKED, NOT ASSUMED. A record is stamped with the estate that
 * was open when it was made, and a replay under a different estate is refused. It
 * should not be reachable — the unique key already includes the principal, and a
 * principal belongs to one estate — but "not reachable" is what everybody believed
 * about the answer being isolated, so it is a check rather than an argument.
 */
final class Idempotent
{
    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $key = $request->header('Idempotency-Key') ?? $request->input('idempotency_key');

        if ($key === null || $key === '') {
            // `opaque` is `required` with a narrower hash — it was letting a keyless write through (found M4 §3.4).
            if ($mode === 'required' || $mode === 'opaque') {
                throw ApiError::unprocessable('idempotency_key_required', 'Send an Idempotency-Key on every write, chosen before the first attempt and reused on every retry.');
            }

            return $next($request);
        }

        $key = (string) $key;

        if (strlen($key) > 64) {
            throw ApiError::unprocessable('idempotency_key_invalid', 'An Idempotency-Key is at most 64 characters.');
        }

        $principal = Auth::guard('sanctum')->user();
        $type = $principal === null ? 'anonymous' : $principal->getMorphClass();
        $id = $principal === null ? 0 : (int) $principal->getKey();
        $hash = $mode === 'opaque'
            ? hash('sha256', $request->method().' '.$request->path())
            : $this->hash($request);
        $route = (string) ($request->route()?->getName() ?? $request->path());

        $connection = DB::connection('mysql');
        $estate = IdempotencyStore::openEstate();

        try {
            $rowId = $connection->table(IdempotencyStore::CENTRAL_TABLE)->insertGetId([
                'tokenable_type' => $type,
                'tokenable_id' => $id,
                'tenant_id' => $estate,
                'idempotency_key' => $key,
                'route' => $route,
                'request_hash' => $hash,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        } catch (QueryException $duplicate) {
            if (! str_contains($duplicate->getMessage(), 'api_idempotency_unique') && ($duplicate->errorInfo[1] ?? null) !== 1062) {
                throw $duplicate;
            }

            $existing = $connection->table(IdempotencyStore::CENTRAL_TABLE)
                ->where('tokenable_type', $type)
                ->where('tokenable_id', $id)
                ->where('idempotency_key', $key)
                ->first();

            if ($existing === null) {
                throw $duplicate;
            }

            if ($existing->route !== $route || $existing->request_hash !== $hash) {
                throw ApiError::unprocessable('idempotency_key_reused', 'This Idempotency-Key was already used for a different request. Choose a new key for a new act.');
            }

            /*
             * A record made under one estate is never answered under another, and a
             * record made with no estate open is never answered with one. Same
             * refusal as any other mismatch: the key stands for an act that is not
             * the one being asked for.
             *
             * A record written before the stamp existed carries neither, and is
             * replayed as it always was — the unique key still scopes it to one
             * principal, and refusing those would turn every retry that was in
             * flight across the deploy into a wrong answer. They are gone within the
             * retention window, or at once with `api:prune-idempotency --clear-legacy`.
             */
            $stamped = ($existing->tenant_id ?? null) !== null || ($existing->body_store ?? null) !== null;

            if ($stamped && ($existing->tenant_id ?? null) !== $estate) {
                throw ApiError::unprocessable('idempotency_key_reused', 'This Idempotency-Key was already used for a different request. Choose a new key for a new act.');
            }

            if ($existing->status_code === null) {
                $rowId = $this->takeOverAbandoned($existing, $route, $estate);
            } else {
                return $this->replay($existing, $route, $estate);
            }
        }

        try {
            $response = $next($request);
        } catch (\Throwable $failure) {
            $status = $failure instanceof ApiError ? $failure->status : 500;

            if ($failure instanceof ApiError) {
                IdempotencyStore::remember($rowId, $status, $failure->body());
            } else {
                IdempotencyStore::forget($rowId);
            }

            throw $failure;
        }

        if ($response->getStatusCode() >= 500) {
            IdempotencyStore::forget($rowId);

            return $response;
        }

        $body = json_decode((string) $response->getContent(), true);
        IdempotencyStore::remember($rowId, $response->getStatusCode(), is_array($body) ? $body : []);

        return $response;
    }

    /**
     * A record with no answer yet: its first attempt is running, or it died (bug hunt B062,
     * B135). Returns the record's id when this retry may run the act itself.
     *
     *   running (younger than the bound)          409 `request_in_progress` — wait and retry
     *   dead, and the act de-duplicates itself    run again, once: the phone gets the alert
     *                                             the first attempt raised, or it is raised
     *   dead, anything else                       409 `idempotent_answer_missing` — final
     *
     * "Still running" was the answer for all three, and against a dead attempt it is never
     * true: the outbox waited on it, and every act queued behind it, until the prune took
     * the record thirty days later.
     */
    private function takeOverAbandoned(object $existing, string $route, ?string $estate): int
    {
        if (! IdempotencyStore::isAbandoned($existing)) {
            throw new ApiError(409, 'request_in_progress', 'The first attempt with this key is still being handled. Retry in a moment with the same key.');
        }

        if (in_array($existing->route, IdempotencyStore::SELF_DEDUPLICATING_ROUTES, true)) {
            // Two retries racing for the same dead record: one runs it, the other waits for its answer.
            if (! IdempotencyStore::claimAbandoned($existing)) {
                throw new ApiError(409, 'request_in_progress', 'The first attempt with this key is still being handled. Retry in a moment with the same key.');
            }

            Log::warning('An abandoned idempotency record was run again; the act de-duplicates on its own key.', [
                'record' => (int) $existing->id,
                'route' => $route,
                'estate' => $estate,
            ]);

            return (int) $existing->id;
        }

        /*
         * THE FIRST ATTEMPT DIED, AND NOTHING SAYS WHETHER THE ACT HAPPENED. Running it
         * again could do it twice — a second ticket, a second clock-out — so it is not.
         * Final, so the queue moves past it and the person is told to check.
         */
        Log::warning('An abandoned idempotency record was refused rather than repeated: its first attempt never finished.', [
            'record' => (int) $existing->id,
            'route' => $route,
            'estate' => $estate,
        ]);

        throw new ApiError(409, 'idempotent_answer_missing', 'This was already sent, but the server stopped before it could answer, so it is not known whether it went through. Do not send it again — check whether it went through.');
    }

    /** The stored answer to a record that has one, byte for byte. */
    private function replay(object $existing, string $route, ?string $estate): Response
    {
        $body = IdempotencyStore::recall($existing);

        if ($body === null) {
            /*
             * THE ACT HAPPENED AND THE ANSWER IS GONE. Only a hand-edited
             * database or a changed APP_KEY can get here: the answer is written
             * before the record is marked answered, and the prune takes the
             * record before the answer. Running the act again is the one
             * outcome worse than refusing — a second alert, a second ticket, a
             * second payment — so this refuses, and says so where an operator
             * will see it.
             *
             * A CODE OF ITS OWN, AND DELIBERATELY NOT `request_in_progress`:
             * the outbox keeps retrying that one for ever, which against a
             * record that will never gain an answer is an endless loop. This is
             * final, so the queued act stops and the person is told.
             */
            Log::critical('An idempotency record has a status but no answer; the retry was refused rather than repeated.', [
                'record' => (int) $existing->id,
                'route' => $route,
                'estate' => $estate,
            ]);

            throw new ApiError(409, 'idempotent_answer_missing', 'This was already sent and the server handled it, but its answer is no longer stored. Do not send it again — check whether it went through.');
        }

        return (new JsonResponse($body, (int) $existing->status_code))
            ->header('Idempotent-Replayed', 'true');
    }

    /**
     * What makes two attempts "the same request". The device time is left out:
     * a retry is sent later than the first attempt, and that must not make it a
     * different request. Files count by name, size and content hash.
     */
    private function hash(Request $request): string
    {
        $input = $request->except(['idempotency_key', 'device_time']);

        foreach ($request->allFiles() as $field => $files) {
            $input['__files'][$field] = array_map(
                static fn (UploadedFile $file): array => [$file->getClientOriginalName(), $file->getSize(), hash_file('sha256', (string) $file->getRealPath())],
                is_array($files) ? $files : [$files],
            );
        }

        ksort($input);

        return hash('sha256', $request->method().' '.$request->path().' '.json_encode($input));
    }
}
