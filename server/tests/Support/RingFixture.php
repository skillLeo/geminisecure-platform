<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Models\DevicePushToken;
use App\Models\EstateAssignment;
use App\Models\Guard;
use App\Models\Post;
use App\Models\ResidentAccount;
use App\Models\Shift;
use App\Models\SiteEmergencyNumber;
use App\Models\Tenant;
use App\Services\Devices\DeviceEnrolment;
use App\Support\SiteTime;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/**
 * The world the guard ring's tests stand on — one place, so every file sets it up alike.
 *
 * THE CENTRAL DATABASE IS WRAPPED IN ONE TRANSACTION with the framework's TESTING manager,
 * so the "after commit" fan-out runs inside the POST as it does in production, and nothing
 * a test writes outlives it.
 *
 * NOTHING REACHES A NETWORK. Expo is `Http::fake`d with an answer per message, and every
 * message handed to it is kept in order so a test can read exactly what each phone was sent.
 *
 * QUEUED WORK IS RUN THE WAY A WORKER RUNS IT. The ring's jobs are pinned to the central
 * connection, which in this suite is the real `database` driver: they are written to the
 * `jobs` table inside the test's transaction. `runJobs()` reads them back in id order,
 * opens the estate each was queued from — as `QueueTenancyBootstrapper` does in a worker
 * (G5) — and runs each one's `handle()`. A job not yet due is left alone unless asked.
 */
final class RingFixture
{
    public const OTHER_ESTATE = 'ringother';

    public static function boot(TestCase $test): void
    {
        FacilitiesFixture::boot();
        FacilitiesFixture::platform();

        $manager = new DatabaseTransactionsManager(['mysql']);
        app()->instance('db.transactions', $manager);
        DB::connection('mysql')->setTransactionManager($manager);
        DB::connection('mysql')->beginTransaction();

        Http::preventStrayRequests();

        // The estate is a real one, on Kingston's clock, and not the demonstration estate.
        Tenant::query()->findOrFail(FacilitiesFixture::ESTATE)->forceFill(['demo' => false, 'timezone' => 'America/Jamaica'])->save();
        SiteTime::flush();

        Tenant::withoutEvents(static function (): void {
            if (Tenant::query()->find(self::OTHER_ESTATE) === null) {
                (new Tenant)->forceFill(['id' => self::OTHER_ESTATE, 'name' => 'Ocean View (ring test)', 'receipt_prefix' => 'RGO', 'status' => 'active'])->save();
            }
        });

        // A bare site: nobody on duty, nobody rostered, nobody else posted, no numbers.
        Shift::query()->where('tenant_id', FacilitiesFixture::ESTATE)->delete();
        Guard::query()->whereIn('tenant_id', [FacilitiesFixture::ESTATE, self::OTHER_ESTATE])->update(['status' => 'inactive', 'device_id' => null]);
        EstateAssignment::query()->where('tenant_id', FacilitiesFixture::ESTATE)->update(['is_active' => false]);
        SiteEmergencyNumber::query()->where('tenant_id', FacilitiesFixture::ESTATE)->delete();

        config([
            'push.enabled' => true,
            'push.coalesce_seconds' => 300,
            'push.receipt_delay_seconds' => 0,
            'ring.enabled' => true,
            'services.sms.driver' => 'log',
        ]);
    }

    public static function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        DB::connection('mysql')->rollBack();
        Carbon::setTestNow();
        SiteTime::flush();
        FacilitiesFixture::boot();
    }

    public static function post(string $tenant = FacilitiesFixture::ESTATE): Post
    {
        return Post::create(['tenant_id' => $tenant, 'name' => 'Ring Gate '.Str::random(4), 'type' => 'gate', 'is_active' => true]);
    }

    /**
     * An active guard with a handset bound — and, unless told otherwise, a push address.
     *
     * @param  array<string, mixed>  $push  columns for the push address; null for none
     * @return array{0: Guard, 1: string, 2: DevicePushToken|null} the guard, their API token, their push address
     */
    public static function guard(string $number, string $tenant = FacilitiesFixture::ESTATE, ?array $push = [], ?int $postId = null): array
    {
        $guard = Guard::create([
            'full_name' => 'Officer '.$number, 'employee_number' => $number, 'psra_number' => 'PSRA-'.$number,
            'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
            'tenant_id' => $tenant, 'post_id' => $postId,
        ]);

        $bearer = app(DeviceEnrolment::class)->enrol($guard, 'Ring handset '.$number)['token'];
        $accessTokenId = (int) $guard->tokens()->latest('id')->value('id');

        $token = $push === null ? null : DevicePushToken::query()->create([
            'tenant_id' => $tenant, 'principal_type' => DevicePushToken::GUARD, 'principal_id' => $guard->id, 'app' => AppMatrix::GUARD,
            'access_token_id' => $accessTokenId, 'expo_token' => self::expoToken($number), 'platform' => 'android', 'muted' => [],
            'last_registered_at' => now(), ...$push,
        ]);

        return [$guard->fresh(), $bearer, $token];
    }

    /** Columns for a push address whose Guard App reported it can ring like a call. */
    public static function ready(string $state = 'ready'): array
    {
        return ['ring_version' => 1, 'ring_state' => $state, 'ring_checked_at' => now()];
    }

    public static function expoToken(string $seed): string
    {
        return 'ExponentPushToken['.substr(str_pad((string) preg_replace('/[^A-Za-z0-9]/', '', $seed).'rrrrrrrrrrrrrrrrrrrrrr', 22, 'r'), 0, 22).']';
    }

    /** A shift today, clocked on or only rostered. Without events: a roster change is its own push. */
    public static function shift(Guard $guard, int $postId, bool $clockedOn): Shift
    {
        $day = SiteTime::today((string) $guard->tenant_id);

        return Shift::withoutEvents(static fn (): Shift => Shift::create([
            'tenant_id' => (string) $guard->tenant_id, 'guard_id' => $guard->id, 'post_id' => $postId,
            'rostered_start' => $day->copy()->setTime(0, 1), 'rostered_end' => $day->copy()->setTime(23, 58),
            'actual_start' => $clockedOn ? Carbon::now()->subHour() : null,
            'status' => $clockedOn ? 'on_duty' : Shift::ROSTERED,
        ]));
    }

    /** A Resident App account at a lot of this file's own, and its bearer token. */
    public static function residentToken(string $email, string $lot): string
    {
        $unit = FacilitiesFixture::scratchUnit($lot);
        $household = \App\Models\Estate\Household::query()->firstOrCreate(['unit_id' => $unit->id], ['name' => 'Ring household '.$lot, 'access_restricted' => false]);
        $resident = \App\Models\Estate\Resident::query()->firstOrCreate(['household_id' => $household->id, 'full_name' => 'Ring Resident '.$lot], ['relationship' => 'owner', 'is_primary' => true]);
        $resident->forceFill(['is_primary' => true, 'removed_at' => null])->save();

        $account = ResidentAccount::query()->create([
            'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => $email, 'full_name' => 'Resident '.$email,
            'resident_id' => $resident->id, 'unit_id' => $unit->id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
        ]);

        return $account->createToken('resident-app:'.$email, AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;
    }

    /** Call an endpoint as the catalogue describes it, holding the answer to its contract. */
    public static function call(TestCase $test, ?string $token, string $name, array $body = [], array $path = [], ?int $status = null, array $headers = []): TestResponse
    {
        $entry = Catalogue::endpoints()[$name];
        $uri = $entry['uri'];

        foreach ($path as $key => $value) {
            $uri = str_replace('{'.$key.'}', (string) $value, $uri);
        }

        app('auth')->forgetGuards();

        $client = $token === null ? $test : $test->withToken($token);

        $response = $client->json($entry['method'], '/api/v1/'.$uri, $body, [
            'Idempotency-Key' => 'ring-'.bin2hex(random_bytes(8)),
            'X-Device-Time' => now()->toIso8601String(),
            ...$headers,
        ]);

        $response->assertStatus($status ?? $entry['status']);
        ApiContract::assertMatches($response, $name);
        FacilitiesFixture::boot();

        // A request with a token leaves the test client carrying it; the next call names its own.
        $test->flushHeaders();

        return $response;
    }

    /** The ring ticket the last `site_ring` (or `ring_test`) sent to this phone carried. */
    public static function ticketFor(DevicePushToken $token, ?int $round = null): string
    {
        $message = self::to($token)
            ->filter(static fn (array $m): bool => in_array($m['data']['type'] ?? null, ['site_ring', 'ring_test'], true) && ($round === null || ($m['data']['r'] ?? null) === $round))
            ->last();

        Assert::assertNotNull($message, 'This phone was sent no ring to take a ticket from.');

        return (string) $message['data']['rt'];
    }

    /* ── Expo ─────────────────────────────────────────────────────────── */

    /**
     * Expo, answering `ok` for every message — or the given error for the phones named.
     *
     * @param  array<string, string>  $errors  expo token => error code
     */
    public static function expo(array $errors = [], ?int $status = null): void
    {
        Http::fake([
            'exp.host/--/api/v2/push/send' => static function (HttpRequest $request) use ($errors, $status) {
                if ($status !== null) {
                    return Http::response(['errors' => [['code' => 'X']]], $status);
                }

                $tickets = array_map(static fn (array $message): array => isset($errors[$message['to'] ?? ''])
                    ? ['status' => 'error', 'details' => ['error' => $errors[$message['to']]]]
                    : ['status' => 'ok', 'id' => (string) Str::uuid()], $request->data());

                return Http::response(['data' => $tickets]);
            },
            'exp.host/--/api/v2/push/getReceipts' => Http::response(['data' => []]),
        ]);
    }

    /**
     * Every message handed to Expo so far, in order, with the batch it went in.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function sent(): Collection
    {
        return collect(Http::recorded())
            ->filter(static fn (array $pair): bool => str_contains($pair[0]->url(), '/push/send'))
            ->flatMap(static fn (array $pair): array => $pair[0]->data())
            ->values();
    }

    /**
     * The messages of one type sent to one phone.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function to(DevicePushToken $token, ?string $type = null): Collection
    {
        return self::sent()->filter(static fn (array $m): bool => ($m['to'] ?? null) === $token->expo_token
            && ($type === null || ($m['data']['type'] ?? null) === $type))->values();
    }

    /* ── the queue, as a worker reads it ──────────────────────────────── */

    /**
     * The jobs of a class waiting on the central queue, oldest first.
     *
     * @return Collection<int, object{id: int, queue: string, available_at: int, payload: array<string, mixed>, command: object}>
     */
    public static function queued(?string $class = null, bool $dueOnly = false): Collection
    {
        return DB::connection('mysql')->table('jobs')->orderBy('id')->get()
            ->map(static function (object $row): object {
                $payload = json_decode((string) $row->payload, true);

                return (object) [
                    'id' => (int) $row->id,
                    'queue' => (string) $row->queue,
                    'available_at' => (int) $row->available_at,
                    'payload' => $payload,
                    'command' => unserialize($payload['data']['command']),
                ];
            })
            ->filter(static fn (object $job): bool => ($class === null || $job->command instanceof $class)
                && (! $dueOnly || $job->available_at <= Carbon::now()->getTimestamp()))
            ->values();
    }

    /**
     * Run the waiting jobs of these classes that are due now, as a worker would: with the
     * estate each was queued from open, and removed from the table once run. Repeats until
     * nothing due is left, so a job that queues another due job has it run too.
     *
     * @param  list<class-string>  $classes
     * @return int how many ran
     */
    public static function runJobs(array $classes, bool $evenIfNotDue = false): int
    {
        $ran = 0;

        for ($pass = 0; $pass < 20; $pass++) {
            $jobs = self::queued(null, ! $evenIfNotDue)->filter(static function (object $job) use ($classes): bool {
                foreach ($classes as $class) {
                    if ($job->command instanceof $class) {
                        return true;
                    }
                }

                return false;
            });

            if ($jobs->isEmpty()) {
                return $ran;
            }

            foreach ($jobs as $job) {
                DB::connection('mysql')->table('jobs')->where('id', $job->id)->delete();

                $tenant = $job->payload['tenant_id'] ?? null;

                if (is_string($tenant) && $tenant !== '') {
                    tenancy()->initialize(Tenant::query()->findOrFail($tenant));
                }

                try {
                    app()->call([$job->command, 'handle']);
                } finally {
                    if (tenancy()->initialized) {
                        tenancy()->end();
                    }

                    FacilitiesFixture::boot();
                }

                $ran++;
            }
        }

        Assert::fail('Jobs kept queueing jobs: stopped after twenty passes.');
    }
}
