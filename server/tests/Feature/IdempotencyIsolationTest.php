<?php

declare(strict_types=1);

use App\Api\Catalogue;
use App\Api\IdempotencyStore;
use App\Models\Guard;
use App\Models\Post;
use App\Models\SecurityIncident;
use App\Models\Tenant;
use App\Services\Devices\DeviceEnrolment;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\OwnEstate;

/*
|--------------------------------------------------------------------------
| A retried write's ANSWER belongs to the estate that made it
|--------------------------------------------------------------------------
|
| THE DEFECT. `App\Http\Middleware\Api\Idempotent` stored `response_body` — the
| full JSON answer to every write the mobile API takes — in `api_idempotency_keys`
| on the central `mysql` connection. This platform is one database per estate, and
| that separation is the product's core claim about how one client's data is kept
| away from another's. A resident's security report was its title and its reference,
| a `sync.batch` reply was the answer to every operation in an uploaded queue, and
| all of it sat in the one database the claim says it is not in. There was no prune
| and no time limit, so it accumulated for ever.
|
| THE PROMISE THAT MUST SURVIVE THE FIX, because an idempotency record that is
| silently lost turns a retry into a DUPLICATE — a second panic alert, a second
| ticket, a second payment, which is worse than the defect:
|
|   a key is chosen by the app BEFORE the first attempt and reused on every retry;
|   the act happens ONCE; every retry is handed the first attempt's response BYTE
|   FOR BYTE with `Idempotent-Replayed: true`; the same key on a different request
|   is refused; a 5xx stores nothing.
|
| So this file proves both halves at once: the answer is in the estate's own
| database and nowhere else, AND the promise above still holds exactly.
|
| BOTH ESTATES ARE THIS FILE'S OWN (M11 B5), because what it asserts is which
| physical database holds a row under a given key id — and in a database other
| files write to, that id can already be taken. The first estate used to be the
| shared facilities estate. Twenty-one other files stand on it, send
| Idempotency-Key writes and roll their central transaction back, so it collects
| answers whose central keys are gone; and a RefreshDatabase file earlier in the
| process rebuilds the central database, which starts `api_idempotency_keys`
| counting from 1 again. Estate B's key was then handed an id under which the
| shared estate already held a stranger's answer ("not_primary"), and "estate A
| does not hold estate B's answer by id" failed although nothing had crossed.
| Planting such answers on the next ids reproduced it every time. `idemtest` is
| dropped and rebuilt at this file's first test in a process, so every answer in
| it was written by this file under ids issued to it in increasing order, and
| the probe means exactly what it says again.
|
*/

/** This file's own first estate: its tenant id and its database suffix. */
function idemEstate(): OwnEstate
{
    static $estate = null;

    return $estate ??= new OwnEstate('idemtest', 'IDA', 'Idempotency Test Estate');
}

/** A second, real estate — its own database — so "cross-estate" is a fact and not an argument. */
const OTHER_ESTATE = 'idemothertest';

beforeEach(function () {
    idemEstate()->boot();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    $this->post = Post::create(['tenant_id' => idemEstate()->key, 'name' => 'Idempotency Gate', 'type' => 'gate', 'is_active' => true]);
    $this->guard = idemGuard('GS-IDEM-1', idemEstate()->key, $this->post->id);
    $this->token = app(DeviceEnrolment::class)->enrol($this->guard, 'Handset')['token'];
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    idemEstate()->boot();
});

function idemGuard(string $number, string $estate, ?int $postId): Guard
{
    return Guard::create([
        'full_name' => 'Idempotency Probe '.$number,
        'employee_number' => $number,
        'psra_number' => 'PSRA-'.$number,
        'psra_expires_on' => now()->addYear()->toDateString(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => $estate,
        'post_id' => $postId,
    ]);
}

/**
 * The second estate: a real database, built once per process by the real tenant
 * migrations, with a central `tenants` row made inside this file's transaction so
 * it is rolled back and no other suite ever sees it.
 */
function otherEstate(): Tenant
{
    static $built = false;

    // The configured prefix, never the literal: see `FacilitiesFixture::database()`.
    $database = config('tenancy.database.prefix').OTHER_ESTATE;

    if (! $built) {
        $owner = DB::connection('mysql_owner');
        $owner->statement('DROP DATABASE IF EXISTS `'.$database.'`');
        $owner->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        config(['database.connections.tenant' => array_merge(config('database.connections.mysql'), ['database' => $database])]);
        DB::purge('tenant');

        Artisan::call('migrate', ['--path' => 'database/migrations/tenant', '--database' => 'tenant', '--force' => true]);

        $built = true;
        idemEstate()->boot();
    }

    $estate = Tenant::query()->find(OTHER_ESTATE);

    if ($estate instanceof Tenant) {
        return $estate;
    }

    /*
     * WITHOUT EVENTS, for the reason `FacilitiesFixture` gives: `TenantCreated`
     * runs the real provisioning pipeline — CreateDatabase, MigrateDatabase, the
     * grants — against a database that is already built above.
     */
    return Tenant::withoutEvents(static function (): Tenant {
        $estate = new Tenant;

        $estate->forceFill([
            'id' => OTHER_ESTATE,
            'name' => 'Second Estate',
            'receipt_prefix' => 'IDM',
            'status' => 'active',
        ])->save();

        return $estate;
    });
}

/**
 * The stored answer, read from ONE NAMED DATABASE.
 *
 * Deliberately not `IdempotencyStore::recall()` and not `$tenant->run()`: what
 * these tests are about is which physical database a row is in, so they say which
 * database they are asking.
 */
function answerIn(string $estate, int $keyId): ?string
{
    config(['database.connections.tenant' => array_merge(config('database.connections.mysql'), ['database' => config('tenancy.database.prefix').$estate])]);
    DB::purge('tenant');

    $body = DB::connection('tenant')->table('api_idempotency_responses')->where('key_id', $keyId)->value('response_body');

    idemEstate()->boot();

    return $body === null ? null : (string) $body;
}

/** The central record for one key, whoever made it. */
function centralRecord(string $key): ?object
{
    return DB::connection('mysql')->table('api_idempotency_keys')->where('idempotency_key', $key)->first();
}

/** One incident filed from a handset, with text distinctive enough to hunt for. */
function fileIncident(object $test, string $token, string $key, string $marker): Illuminate\Testing\TestResponse
{
    app('auth')->forgetGuards();

    $response = $test->withToken($token)->postJson('/api/v1/incidents', [
        'kind' => 'Attempted unauthorized access',
        'severity' => 'med',
        'detail' => $marker.' — a person tried the pedestrian gate twice and left towards the main road.',
        'location' => $marker.' gate',
    ], ['Idempotency-Key' => $key, 'X-Device-Time' => now()->toIso8601String()]);

    idemEstate()->boot();

    return $response;
}

it('keeps a retried write\'s answer in the estate that made it, and none of it in the platform database', function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    $marker = 'ISOLATION-'.bin2hex(random_bytes(4));
    $first = fileIncident($this, $this->token, 'isolation-key-1', $marker)->assertCreated();

    $record = centralRecord('isolation-key-1');

    // 1. THE CENTRAL ROW HOLDS THE KEY, NOT THE CONTENT.
    expect($record)->not->toBeNull()
        ->and($record->tenant_id)->toBe(idemEstate()->key)
        ->and($record->body_store)->toBe(IdempotencyStore::IN_ESTATE)
        ->and($record->response_body)->toBeNull()
        ->and((int) $record->status_code)->toBe(201)
        ->and(json_encode((array) $record))->not->toContain($marker);

    // 2. AND THE ANSWER IS IN THE ESTATE'S OWN DATABASE, in full.
    $stored = answerIn(idemEstate()->key, (int) $record->id);

    expect($stored)->not->toBeNull()
        ->and($stored)->toContain($marker)
        ->and(json_decode((string) $stored, true))->toBe($first->json());

    // 3. THE PROMISE IS UNCHANGED: the retry is answered, byte for byte, and nothing happened twice.
    $replay = fileIncident($this, $this->token, 'isolation-key-1', $marker)->assertCreated();

    expect($replay->getContent())->toBe($first->getContent())
        ->and($replay->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($first->headers->get('Idempotent-Replayed'))->toBeNull()
        ->and(SecurityIncident::query()->where('guard_id', $this->guard->id)->count())->toBe(1)
        ->and(DB::connection('mysql')->table('api_idempotency_keys')->where('idempotency_key', 'isolation-key-1')->count())->toBe(1);

    // 4. AND THE SAME KEY FOR A DIFFERENT ACT IS STILL REFUSED.
    fileIncident($this, $this->token, 'isolation-key-1', 'SOMETHING-ELSE')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'idempotency_key_reused');
});

/*
 * THE ONE THAT MATTERS.
 *
 * Written even though the unique key already makes it impossible — (tokenable_type,
 * tokenable_id, key) is unique across the platform, and a principal belongs to one
 * estate — because "impossible" is exactly what everybody believed about the answer
 * being isolated in the first place.
 *
 * Three separate boundaries are probed: two principals in two real estates, each
 * with a database of its own; a direct read of estate A's answer from estate B's
 * database, by id; and the one case the unique key does NOT cover — the SAME
 * principal, moved to another estate, presenting the key it used at the first.
 */
it('answers a key only in the estate that made it, and never with another estate\'s stored answer', function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    otherEstate();
    $theirGuard = idemGuard('GS-IDEM-2', OTHER_ESTATE, null);
    $theirToken = app(DeviceEnrolment::class)->enrol($theirGuard, 'Their handset')['token'];

    /*
     * TWO KEYS, NOT ONE STRING SHARED, and that is a finding rather than a
     * convenience. `security_incidents.idempotency_key` is UNIQUE ACROSS THE WHOLE
     * PLATFORM, not per estate — as are `duress_alerts`, `gate_events`,
     * `checkpoint_scans` and `dispatch_messages` — so estate B filing on a string
     * estate A already used is refused by MySQL before this middleware is judged at
     * all. (The apps choose v4 UUIDs, so it is unreachable in practice; it is
     * reported, not fixed here.) Part 4 below makes the same-key-two-estates point
     * at the layer this file is about, where nothing else can mask it.
     */
    $ourKey = 'estate-a-key';
    $theirKey = 'estate-b-key';
    $mine = 'ESTATE-A-'.bin2hex(random_bytes(4));
    $theirs = 'ESTATE-B-'.bin2hex(random_bytes(4));

    $ours = fileIncident($this, $this->token, $ourKey, $mine)->assertCreated();
    $them = fileIncident($this, $theirToken, $theirKey, $theirs)->assertCreated();

    // 1. TWO RECORDS, EACH STAMPED WITH ITS OWN ESTATE.
    $records = DB::connection('mysql')->table('api_idempotency_keys')->whereIn('idempotency_key', [$ourKey, $theirKey])->orderBy('id')->get();

    expect($records)->toHaveCount(2)
        ->and($records[0]->tenant_id)->toBe(idemEstate()->key)
        ->and($records[1]->tenant_id)->toBe(OTHER_ESTATE)
        ->and($ours->json('id'))->not->toBe($them->json('id'));

    // 2. EACH ANSWER IS IN ITS OWN DATABASE, AND IN NEITHER OTHER PLACE.
    expect(answerIn(idemEstate()->key, (int) $records[0]->id))->toContain($mine)
        ->and(answerIn(OTHER_ESTATE, (int) $records[1]->id))->toContain($theirs)

        // Estate B's database does not hold estate A's answer, by id or by content.
        ->and(answerIn(OTHER_ESTATE, (int) $records[0]->id))->toBeNull()
        ->and(answerIn(idemEstate()->key, (int) $records[1]->id))->toBeNull();

    foreach (DB::connection('mysql')->table('api_idempotency_keys')->get() as $row) {
        expect(json_encode((array) $row))->not->toContain($mine)->and(json_encode((array) $row))->not->toContain($theirs);
    }

    // 3. EACH HANDSET'S RETRY IS ANSWERED WITH ITS OWN ACT, never the other's.
    $oursAgain = fileIncident($this, $this->token, $ourKey, $mine)->assertCreated();
    $themAgain = fileIncident($this, $theirToken, $theirKey, $theirs)->assertCreated();

    expect($oursAgain->getContent())->toBe($ours->getContent())
        ->and($themAgain->getContent())->toBe($them->getContent())
        ->and($oursAgain->getContent())->not->toContain($theirs)
        ->and($themAgain->getContent())->not->toContain($mine)
        ->and(SecurityIncident::query()->whereIn('guard_id', [$this->guard->id, $theirGuard->id])->count())->toBe(2);

    /*
     * 4. ONE KEY, TWO ESTATES — AND THE CASE THE UNIQUE KEY DOES NOT COVER.
     *
     *    The unique key is (tokenable_type, tokenable_id, key), so two principals
     *    can never collide. The same PRINCIPAL moved to another estate can: this is
     *    the guard who filed in estate A, now attached to estate B, presenting the
     *    key they used at the first. The same record is found, and it belongs to an
     *    estate this request has not opened.
     *
     *    Refused — and estate A's answer is not handed over.
     */
    DB::connection('mysql')->table('guards')->where('id', $this->guard->id)->update(['tenant_id' => OTHER_ESTATE]);

    $moved = fileIncident($this, $this->token, $ourKey, $mine)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'idempotency_key_reused');

    expect($moved->getContent())->not->toContain($mine)
        ->and(SecurityIncident::query()->whereIn('guard_id', [$this->guard->id, $theirGuard->id])->count())->toBe(2);
});

it('keeps a record just inside the window and takes one just outside it, centrally and in the estate', function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    $days = IdempotencyStore::retentionDays();
    $fresh = 'window-inside';
    $stale = 'window-outside';

    // The SAME body on every send: a retry that changed its mind is a different
    // request, and is refused long before any of this is reached.
    $inside = 'INSIDE-'.bin2hex(random_bytes(3));
    $outside = 'OUTSIDE-'.bin2hex(random_bytes(3));

    $answer = fileIncident($this, $this->token, $fresh, $inside)->assertCreated();
    fileIncident($this, $this->token, $stale, $outside)->assertCreated();

    $insideId = (int) centralRecord($fresh)->id;
    $outsideId = (int) centralRecord($stale)->id;

    // One hour inside the window, and one hour outside it.
    idemAge($insideId, Carbon::now()->subDays($days)->addHour());
    idemAge($outsideId, Carbon::now()->subDays($days)->subHour());

    /*
     * The exit code is not the assertion: this shared test database can hold a
     * tenant row whose database another suite never built, and that estate fails.
     * The rows below are what this test is about.
     */
    Artisan::call('api:prune-idempotency');

    // THE ONE INSIDE SURVIVED, AND STILL DE-DUPLICATES.
    expect(centralRecord($fresh))->not->toBeNull()
        ->and(answerIn(idemEstate()->key, $insideId))->not->toBeNull();

    $replay = fileIncident($this, $this->token, $fresh, $inside)->assertCreated();

    expect($replay->getContent())->toBe($answer->getContent())
        ->and($replay->headers->get('Idempotent-Replayed'))->toBe('true');

    /*
     * THE ONE OUTSIDE IS GONE — the key AND the answer, which is the boundary the
     * window draws: past it the key no longer protects the act, and the next send
     * is a first attempt rather than a retry answered from a record kept for ever.
     *
     * What the endpoint then does with that first attempt is the endpoint's own
     * business, and not every one of them agrees: `security_incidents` keeps a
     * UNIQUE `idempotency_key` of its own that outlives this window and refuses the
     * insert outright, while `AlertIntake` and `GateLog` look the key up and return
     * the first row. Both are the safe direction — neither does the act twice — and
     * both are reported. This test therefore stops where its own layer stops.
     */
    expect(centralRecord($stale))->toBeNull()
        ->and(answerIn(idemEstate()->key, $outsideId))->toBeNull()

        // Nothing was done twice while all of that happened.
        ->and(SecurityIncident::query()->where('guard_id', $this->guard->id)->count())->toBe(2);
});

/** Age a record — central row and estate answer together, as they were written. */
function idemAge(int $keyId, Carbon $at): void
{
    DB::connection('mysql')->table('api_idempotency_keys')->where('id', $keyId)->update(['created_at' => $at]);

    config(['database.connections.tenant' => array_merge(config('database.connections.mysql'), ['database' => idemEstate()->database()])]);
    DB::purge('tenant');
    DB::connection('tenant')->table('api_idempotency_responses')->where('key_id', $keyId)->update(['created_at' => $at]);

    idemEstate()->boot();
}

it('refuses a replay whose answer has gone rather than doing the act a second time', function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    $marker = 'LOST-'.bin2hex(random_bytes(3));

    fileIncident($this, $this->token, 'answer-lost', $marker)->assertCreated();

    $record = centralRecord('answer-lost');

    // The one thing that should never happen: the record kept its status and lost its answer.
    config(['database.connections.tenant' => array_merge(config('database.connections.mysql'), ['database' => idemEstate()->database()])]);
    DB::purge('tenant');
    DB::connection('tenant')->table('api_idempotency_responses')->where('key_id', $record->id)->delete();
    idemEstate()->boot();

    /*
     * A FINAL REFUSAL, not `request_in_progress`: the outbox retries that one for
     * ever, and this record will never gain an answer. Doing the act again is the
     * one outcome worse than refusing it.
     */
    fileIncident($this, $this->token, 'answer-lost', $marker)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'idempotent_answer_missing');

    expect(SecurityIncident::query()->where('guard_id', $this->guard->id)->count())->toBe(1);
});

it('stores the answer centrally and ENCRYPTED when no estate is open, and reads it back', function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }

    $marker = 'NO-ESTATE-'.bin2hex(random_bytes(4));

    $rowId = DB::connection('mysql')->table('api_idempotency_keys')->insertGetId([
        'tokenable_type' => 'anonymous', 'tokenable_id' => 0, 'tenant_id' => null,
        'idempotency_key' => 'no-estate-open', 'route' => 'api.v1.devices.enrol',
        'request_hash' => hash('sha256', 'probe'), 'created_at' => now(), 'updated_at' => now(),
    ]);

    IdempotencyStore::remember($rowId, 201, ['status' => 'enrolled', 'token' => $marker]);

    $row = DB::connection('mysql')->table('api_idempotency_keys')->where('id', $rowId)->first();

    expect($row->body_store)->toBe(IdempotencyStore::IN_CENTRAL)
        ->and($row->response_body)->not->toBeNull()

        // The one central row that has to exist is not readable from a dump.
        ->and((string) $row->response_body)->not->toContain($marker)
        ->and(Crypt::decryptString((string) $row->response_body))->toContain($marker)

        // And the promise still holds: the answer comes back exactly as it went in.
        ->and(IdempotencyStore::recall($row))->toBe(['status' => 'enrolled', 'token' => $marker]);

    idemEstate()->boot();
});

/*
 * THE INVARIANT THE DESIGN RESTS ON.
 *
 * `idempotent` is added by `routes/api.php` only when an entry is a `write`, and
 * `device:` — which is what opens the estate — only when it has a `capability`. So
 * every write runs with an estate open, which is why the answer always has an estate
 * database to go to. Sign-in, enrolment and the public routes are `write => false`
 * and store nothing at all.
 *
 * If this ever fails, the endpoint that broke it stores its answer CENTRALLY and
 * encrypted, which works but puts a row back in the platform database. Whoever adds
 * it should decide that deliberately, here, rather than discover it later.
 */
it('gives every write an estate to keep its answer in', function () {
    $keyless = [];

    foreach (Catalogue::endpoints() as $name => $entry) {
        if ($entry['write'] && $entry['capability'] === null) {
            $keyless[] = $name;
        }
    }

    expect($keyless)->toBe([], implode("\n", [
        'These endpoints are writes with no capability, so they carry `idempotent` without `device:`:',
        '    '.implode(', ', $keyless),
        '',
        'They run with NO estate open, so the answer stored for their Idempotency-Key has no',
        'estate database to go to and falls back to the central row, encrypted with the',
        'application key (App\Api\IdempotencyStore). That is deliberate and it works — but it',
        'is a row of estate-adjacent content back in gs_platform, so say so here on purpose.',
    ]));
});

/*
 * AND THE TWO GOOD PROPERTIES THE REVIEWER CONFIRMED, PINNED SO THEY CANNOT DRIFT:
 * the REQUEST is only ever hashed and never stored, and for a ballot the hash is
 * narrowed to the method and path. A column list is a blunt instrument and that is
 * the point — nobody adds `request_body` to this table by accident.
 */
it('stores no request, only a hash, and keeps both tables to what they are for', function () {
    $central = Schema::connection('mysql')->getColumnListing('api_idempotency_keys');
    sort($central);

    expect($central)->toBe([
        'body_store', 'created_at', 'id', 'idempotency_key', 'request_hash',
        'response_body', 'route', 'status_code', 'tenant_id', 'tokenable_id', 'tokenable_type', 'updated_at',
    ]);

    config(['database.connections.tenant' => array_merge(config('database.connections.mysql'), ['database' => idemEstate()->database()])]);
    DB::purge('tenant');

    $estate = Schema::connection('tenant')->getColumnListing('api_idempotency_responses');
    sort($estate);

    idemEstate()->boot();

    expect($estate)->toBe(['created_at', 'id', 'key_id', 'response_body', 'updated_at']);

    // And the hash is of the request, not the request.
    $this->withoutMiddleware(ThrottleRequests::class);

    $marker = 'HASHED-'.bin2hex(random_bytes(4));
    fileIncident($this, $this->token, 'hash-only', $marker)->assertCreated();

    $record = centralRecord('hash-only');

    expect($record->request_hash)->toHaveLength(64)
        ->and($record->request_hash)->not->toContain($marker)
        ->and(json_encode((array) $record))->not->toContain($marker);
});
