<?php

declare(strict_types=1);

namespace App\Api;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WHERE A RETRIED WRITE'S ANSWER IS KEPT, AND FOR HOW LONG.
 *
 * `App\Http\Middleware\Api\Idempotent` makes one promise: a write sent twice with
 * the same key happens once, and the second send is handed the first send's
 * response byte for byte. Keeping that promise means keeping the response. This
 * class is the only place that decides WHERE it is kept.
 *
 * THE DEFECT THIS EXISTS TO FIX. The response was stored in `response_body` on
 * `api_idempotency_keys`, on the central `mysql` connection — the platform database
 * every client on this platform shares. The full JSON body of every write: a
 * resident's security report by title and reference, a maintenance ticket, and for
 * `sync.batch` the answer to every operation in an uploaded offline queue. This
 * product's core claim is a database per estate; that body was the one thing
 * routinely crossing back out of it. There was no prune and no time limit, so it
 * accumulated for the life of the platform.
 *
 * WHAT MOVED AND WHAT DID NOT.
 *
 *   the key           STAYS CENTRAL. (tokenable_type, tokenable_id, key) is unique
 *                     across the whole platform, which is what stops a key being
 *                     replayed into a second estate at all, and the "first attempt
 *                     still running" lock has to be one lock rather than one per
 *                     database. None of it is estate content: a type, an id, a
 *                     route name, a hash and a status code.
 *   the request       NEVER STORED, only hashed, exactly as before. For a ballot
 *                     the hash is narrowed to the method and path, so no ballot
 *                     content is retained. Neither property is touched here.
 *   the response      MOVES to `api_idempotency_responses` in the estate's own
 *                     database, whenever an estate is open — which, today, is every
 *                     write this API has (see `IdempotencyIsolationTest`).
 *
 * AND WHEN NO ESTATE IS OPEN. Sign-in, enrolment and the public routes have no
 * tenant database to write to. Today not one of them is a `write` in the catalogue,
 * so not one of them reaches this class, and a test holds that line. If the line is
 * ever crossed the answer is stored centrally and ENCRYPTED with the application
 * key — the platform's own answers (an enrolment response carries a bearer token)
 * have no estate to belong to, and a central row that must exist should at least be
 * unreadable in a backup, a replica or a dump.
 *
 * THE ORDER, WHICH IS THE WHOLE SAFETY ARGUMENT. An idempotency record that is
 * silently lost turns a retry into a DUPLICATE — a second panic alert, a second
 * ticket, a second payment. So:
 *
 *   writing   the estate row is written FIRST, and only then is the central row
 *             marked answered. A crash between the two leaves an orphan in the
 *             estate (swept by the prune), never a central record whose answer is
 *             missing. If the estate write fails outright the answer is kept
 *             centrally and encrypted rather than dropped, and the failure is
 *             logged as the isolation regression it is.
 *   pruning   the central row goes FIRST, for the same reason from the other end.
 *   reading   a record whose answer cannot be found is NOT re-run. It is refused,
 *             loudly, because re-running it is the one outcome worse than refusing.
 */
final class IdempotencyStore
{
    public const CENTRAL_TABLE = 'api_idempotency_keys';

    public const TENANT_TABLE = 'api_idempotency_responses';

    /** The answer is in the estate's own database. */
    public const IN_ESTATE = 'tenant';

    /** The answer is on the central row, encrypted — no estate was open. */
    public const IN_CENTRAL = 'central';

    /**
     * After this long unanswered, a record's first attempt is dead, not running (bug hunt
     * B062, B135).
     *
     * The record is written, unanswered, BEFORE the write runs, and only the code after the
     * write settles it. A worker that dies in between never settles it: an FPM reload in a
     * deploy, "Maximum execution time exceeded", exhausted memory, a host restart. Every
     * retry was then told `request_in_progress` however old the record was, the outbox
     * rightly waits on that, and the act stopped the phone's whole queue until the prune.
     *
     * Five minutes is ten times PHP's default limit and far past any real API write, so
     * a record this old cannot have a live first attempt behind it.
     */
    public const ABANDONED_AFTER_SECONDS = 300;

    /**
     * Writes that de-duplicate on their own key, so running an abandoned one again cannot
     * do it twice: `AlertIntake` returns the alert already raised under the key. Anything
     * else is answered the final `idempotent_answer_missing` instead of being re-run.
     */
    public const SELF_DEDUPLICATING_ROUTES = ['api.v1.duress.store', 'api.v1.alerts.store'];

    /**
     * How long a record is kept.
     *
     * A RETRY, NOT A CUSTOMER. The handset chooses its key before the first attempt
     * and reuses it until it gets an answer; the outbox warns a guard at twelve
     * hours (`OUTBOX_LIMITS.warnAgeHours`) and drops nothing, so the longest real
     * gap between a first attempt and its last retry is a phone that was switched
     * off. Thirty days is sixty times the point at which the app itself calls the
     * queue old — long enough that no honest retry can outlive it, short enough
     * that an answer is gone within the month rather than kept for ever.
     */
    public static function retentionDays(): int
    {
        return max(1, (int) config('api.idempotency_retention_days', 30));
    }

    /** Whether a record is unanswered and older than any first attempt could still be running. */
    public static function isAbandoned(object $row): bool
    {
        if (($row->status_code ?? null) !== null) {
            return false;
        }

        $touched = Carbon::parse((string) ($row->updated_at ?? $row->created_at));

        return $touched->lessThan(Carbon::now()->subSeconds(self::ABANDONED_AFTER_SECONDS));
    }

    /**
     * Take an abandoned record over for a retry — one retry, and only one.
     *
     * One guarded UPDATE: it succeeds only while the row is still unanswered and still
     * stale, and it restarts the row's clock, so a second retry racing the first finds it
     * running and is told so.
     */
    public static function claimAbandoned(object $row): bool
    {
        return DB::connection('mysql')->table(self::CENTRAL_TABLE)
            ->where('id', $row->id)
            ->whereNull('status_code')
            ->where('updated_at', '<', Carbon::now()->subSeconds(self::ABANDONED_AFTER_SECONDS))
            ->update(['updated_at' => Carbon::now()]) === 1;
    }

    /** The estate open on this request, or null when the request opened none. */
    public static function openEstate(): ?string
    {
        $key = tenant()?->getTenantKey();

        return $key === null ? null : (string) $key;
    }

    /**
     * Keep the answer to the write this record stands for.
     *
     * Returns the store it landed in, which the caller writes onto the central row.
     *
     * @param  array<mixed>  $body
     */
    public static function remember(int $rowId, int $status, array $body): void
    {
        $json = (string) json_encode($body);
        $estate = self::openEstate();
        $store = self::IN_CENTRAL;
        $central = null;

        if ($estate !== null) {
            try {
                DB::connection('tenant')->table(self::TENANT_TABLE)->updateOrInsert(
                    ['key_id' => $rowId],
                    ['response_body' => $json, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
                );

                $store = self::IN_ESTATE;
            } catch (Throwable $failure) {
                /*
                 * The estate's database refused the row. The answer must not be
                 * lost — losing it turns the next retry into a second act — so it
                 * is kept centrally, encrypted, and this is said plainly because
                 * it IS the defect recurring, one row at a time.
                 */
                Log::critical('Idempotency answer could not be stored in the estate database; kept centrally and encrypted.', [
                    'estate' => $estate,
                    'record' => $rowId,
                    'reason' => $failure->getMessage(),
                ]);
            }
        }

        if ($store === self::IN_CENTRAL) {
            $central = Crypt::encryptString($json);
        }

        DB::connection('mysql')->table(self::CENTRAL_TABLE)->where('id', $rowId)->update([
            'status_code' => $status,
            'body_store' => $store,
            'response_body' => $central,
            'updated_at' => Carbon::now(),
        ]);
    }

    /**
     * The stored answer for a record, or null when it cannot be read back.
     *
     * Null is never "so run it again". The caller refuses instead.
     *
     * @return array<mixed>|null
     */
    public static function recall(object $row): ?array
    {
        $store = $row->body_store ?? null;

        $json = match ($store) {
            self::IN_ESTATE => self::fromEstate((int) $row->id),
            self::IN_CENTRAL => self::decrypt((string) ($row->response_body ?? ''), (int) $row->id),

            /*
             * A record written before the answer moved out of the central database.
             * Its body is there, in the clear, and it is still replayable — which is
             * the point of not dropping the column. The prune takes the last of
             * them thirty days after this ships.
             */
            default => $row->response_body === null ? null : (string) $row->response_body,
        };

        if ($json === null) {
            return null;
        }

        $body = json_decode($json, true);

        return is_array($body) ? $body : null;
    }

    /** Forget a record entirely — a 5xx, or a failure that deserves a real second attempt. */
    public static function forget(int $rowId): void
    {
        DB::connection('mysql')->table(self::CENTRAL_TABLE)->where('id', $rowId)->delete();

        if (self::openEstate() === null) {
            return;
        }

        try {
            DB::connection('tenant')->table(self::TENANT_TABLE)->where('key_id', $rowId)->delete();
        } catch (Throwable $failure) {
            // An orphan the prune will sweep. Never worth failing the request over.
            Log::warning('Idempotency answer left behind in the estate database.', ['record' => $rowId, 'reason' => $failure->getMessage()]);
        }
    }

    /** How many central records are older than the cut-off. */
    public static function centralDue(Carbon $before): int
    {
        return (int) DB::connection('mysql')->table(self::CENTRAL_TABLE)->where('created_at', '<', $before)->count();
    }

    /**
     * Central records older than the cut-off, in batches so a year of neglect
     * cannot hold a lock across the whole table while handsets are inserting.
     */
    public static function pruneCentral(Carbon $before, int $batch = 5000): int
    {
        $deleted = 0;

        while (($gone = DB::connection('mysql')->table(self::CENTRAL_TABLE)->where('created_at', '<', $before)->limit($batch)->delete()) > 0) {
            $deleted += $gone;

            if ($gone < $batch) {
                break;
            }
        }

        return $deleted;
    }

    /** The same, in whichever estate the tenant connection is pointed at. */
    public static function pruneEstate(Carbon $before, int $batch = 5000): int
    {
        $deleted = 0;

        while (($gone = DB::connection('tenant')->table(self::TENANT_TABLE)->where('created_at', '<', $before)->limit($batch)->delete()) > 0) {
            $deleted += $gone;

            if ($gone < $batch) {
                break;
            }
        }

        return $deleted;
    }

    /** How many estate answers are older than the cut-off, in the open estate. */
    public static function estateDue(Carbon $before): int
    {
        return (int) DB::connection('tenant')->table(self::TENANT_TABLE)->where('created_at', '<', $before)->count();
    }

    private static function fromEstate(int $rowId): ?string
    {
        if (self::openEstate() === null) {
            return null;
        }

        $body = DB::connection('tenant')->table(self::TENANT_TABLE)->where('key_id', $rowId)->value('response_body');

        return $body === null ? null : (string) $body;
    }

    private static function decrypt(string $payload, int $rowId): ?string
    {
        if ($payload === '') {
            return null;
        }

        try {
            return Crypt::decryptString($payload);
        } catch (DecryptException $failure) {
            /*
             * The application key changed under a live record. Rotating APP_KEY is
             * planned work, and `docs/DEPLOY.md` says to prune this table as part of
             * it; if it was not done, this says so rather than guessing.
             */
            Log::critical('A stored idempotency answer could not be decrypted — has APP_KEY changed?', ['record' => $rowId]);

            return null;
        }
    }
}
