<?php

declare(strict_types=1);

namespace App\Services\Live;

use App\Api\AppMatrix;
use App\Http\Middleware\BindSessionToItsDoor;
use App\Live\ChannelName;
use App\Live\Channels;
use App\Live\Live;
use App\Models\Guard;
use App\Models\ResidentAccount;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * M11 Part C's isolation proof for live updates: gate:isolation STEPS 11, 12 and 13 (DESIGN.md
 * section 10.1). Every row of section 3.7 that a principal found in the data can reach is asked
 * through the REAL doors, in process, through the whole middleware stack.
 *
 *   11  the console session's door, POST /live/auth, on the estate's own host and the central one
 *   12  the handsets' door, POST /api/v1/live/auth, with tokens minted here and deleted by id
 *       (never DeviceEnrolment::enrol, which would revoke the handset the guard is holding)
 *   13  catch-up refuses whatever the grant refuses, with no events, on both doors
 *
 * THE RULES OF EVIDENCE.
 *   - The positive control of each door comes FIRST, and it is a real grant: `auth` is recomputed
 *     here as key:hmac_sha256(secret, socket_id:channel:channel_data) from the reverb credentials.
 *     A door that answers 200 to everything, or signs with the wrong key, fails the control, and a
 *     door whose control failed proves nothing about its refusals, which are then not counted as
 *     passes.
 *   - A 200 grant on a row that must be refused is a LEAK. A refusal is 403 in the single form, or
 *     the name listed as refused in a 200 batch; anything else where a refusal was expected (a 419,
 *     a 302, a 401, a 500) proves nothing and is a FAIL. Where the right answer is a different
 *     status (a suspended session is signed out: 401), the row says so.
 *   - A foreign record's id and an id that does not exist are answered byte for byte alike.
 *
 * NOTHING SURVIVES IT. The status variants (suspended, unverified, an inactive assignment, a
 * revoked or suspended account, a suspended or reassigned guard, a pending account) are made by
 * changing or adding rows inside ONE central transaction under Live::muted(), and the transaction
 * is rolled back at the end, with the tokens and grants the doors wrote. Other sessions never see
 * any of it. A test runs the same class against estates of its own (CrossTenantChannelProbeTest).
 *
 * Pure ASCII, like the command that prints it.
 */
final class CrossTenantChannelProbe
{
    /** The socket every grant here is asked for; any `digits.digits` would do. */
    public const SOCKET = '7788.4455';

    /** An id no row has: the "does not exist" half of every existence check. */
    private const NOBODY = 987654321;

    /** @var list<array{status: string, proves: string, detail: string}> */
    private array $results = [];

    /**
     * @param  array{member?: User|null, guard?: Guard|null, resident?: ResidentAccount|null}  $subjects
     *                                                                                                     principals of estate A found in the data:
     *                                                                                                     an estate console account, an active guard, an active resident
     *                                                                                                     account with a household. A missing one SKIPs its rows, and says why.
     * @return list<array{status: string, proves: string, detail: string}>
     */
    public function run(Tenant $a, Tenant $b, array $subjects): array
    {
        $this->results = [];
        $db = DB::connection((string) config('tenancy.database.central_connection', 'mysql'));

        // Its own level only: a caller's transaction (a test's) is the caller's to end.
        $level = $db->transactionLevel();
        $db->beginTransaction();

        try {
            Live::muted(function () use ($a, $b, $subjects): void {
                $member = $subjects['member'] ?? null;
                $guard = $subjects['guard'] ?? null;
                $resident = $subjects['resident'] ?? null;

                $member === null
                    ? $this->skip('STEP 11 - the console door', "no active, verified estate console account of {$a->getTenantKey()} to probe with")
                    : $this->consoleDoor($a, $b, $member);

                $guard === null
                    ? $this->skip('STEP 12 - the guard door', "no active guard of {$a->getTenantKey()} to probe with")
                    : $this->guardDoor($a, $b, $guard);

                $resident === null
                    ? $this->skip('STEP 12 - the resident door', "no active resident account of {$a->getTenantKey()} with a household to probe with")
                    : $this->residentDoor($a, $b, $resident);

                $this->pendingDoor($a);
            });
        } catch (Throwable $e) {
            $this->fail('the channel probe ran to the end', class_basename($e).': '.$e->getMessage());
        } finally {
            while ($db->transactionLevel() > $level) {
                $db->rollBack();
            }

            Auth::guard('web')->logout();
            app('auth')->forgetGuards();
            tenancy()->end();
        }

        return $this->results;
    }

    /* ---- STEP 11: the console session ----------------------------------------------------------- */

    private function consoleDoor(Tenant $a, Tenant $b, User $member): void
    {
        $ta = (string) $a->getTenantKey();
        $tb = (string) $b->getTenantKey();
        $own = Channels::user($member->id);
        $wire = static fn (string $name): string => ChannelName::WIRE_PREFIX.$name;

        // CONTROL: the person's own channel, on their estate's own host, truly signed.
        $control = $this->webAuth($member, $a, [$wire($own)]);
        $signed = $this->signedFor($control, $wire($own), 'u-'.$member->id, batch: true);
        $this->check($signed, "CONTROL - {$member->email} is granted {$own} on {$ta}'s host, signed with the reverb key", $this->describe($control));

        if (! $signed) {
            $this->skip('STEP 11 - every console refusal', 'the positive control failed, so no refusal below would mean anything');

            return;
        }

        // The estate feeds this person may hold at home, for the assignment row below.
        $feeds = array_map(static fn (string $feed): string => $wire(Channels::estateFeed($ta, $feed)), Channels::ESTATE_FEEDS);
        $held = array_keys((array) ($this->json($this->webAuth($member, $a, $feeds))['channels'] ?? []));
        $this->check($held !== [], "CONTROL - {$member->email} holds at least one of {$ta}'s own feeds (".implode(', ', array_map(static fn ($w) => substr((string) $w, strlen(ChannelName::WIRE_PREFIX)), $held)).')', 'granted none of '.implode(', ', Channels::ESTATE_FEEDS));

        $this->refusedInBatch($member, $a, [
            $wire("estate.{$tb}.security") => 'another estate\'s security feed',
            $wire("estate.{$tb}.facilities") => 'another estate\'s maintenance feed',
            $wire('estate.zzzq.security') => 'an estate that does not exist',
            $wire("household.{$ta}.1") => 'a household channel (a console user is no resident)',
            $wire("guard.{$ta}.{$member->id}") => 'guard.{t}.N where N is this user\'s own id',
            $wire("resident.{$ta}.{$member->id}") => 'resident.{t}.N where N is this user\'s own id',
            $wire('gemini.dispatch') => 'the Gemini dispatch fan-in',
            $wire("gemini.{$ta}.dispatch") => 'the Gemini dispatch copy of this estate',
            $wire('user.'.($member->id + 1)) => 'another user\'s own channel',
            'presence-live.'.$own => 'a presence alias of the own channel',
            'private-live.User.'.$member->id => 'the own channel in the wrong case',
            'private-live.user.0'.$member->id => 'the own channel with a leading zero',
            "private-estate.{$ta}.alerts" => 'the legacy panic channel, at the live door',
        ], "{$ta}'s host");

        // HOST BINDING: on estate B's host, nothing of A but the person's own channel.
        $onB = $this->webAuth($member, $b, [$wire($own), $wire("estate.{$ta}.security")]);
        $this->check(
            $this->signedFor($onB, $wire($own), 'u-'.$member->id, batch: true) && in_array($wire("estate.{$ta}.security"), (array) ($this->json($onB)['refused'] ?? []), true),
            "on {$tb}'s host a {$ta} account keeps its own channel and is refused {$ta}'s security feed",
            $this->describe($onB),
        );

        // EXISTENCE: a record of B and a record nobody has are answered alike, byte for byte.
        $foreign = $this->webAuthOne($member, $a, $wire("estate.{$tb}.facilities.ticket.1"));
        $missing = $this->webAuthOne($member, $a, $wire("estate.{$tb}.facilities.ticket.".self::NOBODY));
        $this->check(
            $foreign['status'] === 403 && $missing['status'] === 403 && $foreign['body'] === $missing['body'],
            'a foreign record and a record that does not exist get the same 403, byte for byte',
            "foreign {$foreign['status']} ".substr($foreign['body'], 0, 80)." / missing {$missing['status']} ".substr($missing['body'], 0, 80),
        );

        // NO AUTHENTICATION: 401, and no grant.
        $anonymous = $this->web(null, $a, 'POST', 'live/auth', ['socket_id' => self::SOCKET, 'channel_names' => [$wire($own)]]);
        $this->check($anonymous['status'] === 401, 'a request with no session is 401 and grants nothing', $this->describe($anonymous));

        // CATCH-UP (STEP 13): what the door refuses, catch-up refuses, with no events.
        $caught = $this->json($this->web($member, $a, 'POST', 'live/catch-up', ['channels' => [
            ['name' => $own, 'c' => null], ['name' => "estate.{$tb}.security", 'c' => 0, 'e' => 0], ['name' => 'gemini.dispatch', 'c' => 0],
        ]]));
        $statuses = array_column((array) ($caught['channels'] ?? []), 'status', 'name');
        $this->check(
            ($statuses[$own] ?? null) === 'ok'
                && ($statuses["estate.{$tb}.security"] ?? null) === 'refused'
                && ($statuses['gemini.dispatch'] ?? null) === 'refused'
                && ! str_contains((string) json_encode($caught), '"events":[{'),
            'STEP 13 - the console\'s catch-up answers its own channel and refuses the foreign feed and the fan-in, with no events',
            (string) json_encode($statuses),
        );

        // STATUS, inside the transaction and rolled back.
        $firstFeed = $held[0] ?? null;

        if ($firstFeed !== null) {
            DB::connection('mysql')->table('estate_assignments')->where('user_id', $member->id)->where('tenant_id', $ta)->update(['is_active' => false]);
            $inactive = $this->webAuth($member->fresh() ?? $member, $a, [(string) $firstFeed]);
            $this->refusal($inactive, (string) $firstFeed, 'an estate feed once the account\'s assignment to the estate is inactive');
            DB::connection('mysql')->table('estate_assignments')->where('user_id', $member->id)->where('tenant_id', $ta)->update(['is_active' => true]);
        }

        $member->forceFill(['contact_email_verified_at' => null])->saveQuietly();
        $unverified = $member->fresh();

        if ($unverified instanceof User && $unverified->needsContactVerification()) {
            $this->refusal($this->webAuthOne($unverified, $a, $wire($own)), $wire($own), 'the own channel of an account that has not proven an alert address', single: true);
        } else {
            $this->skip('an unverified account is refused', "{$member->email} is not subject to the contact gate");
        }

        $member->forceFill(['status' => 'suspended'])->saveQuietly();
        $suspended = $this->webAuth($member->fresh() ?? $member, $a, [$wire($own)]);
        $this->check(
            $suspended['status'] === 401,
            'a suspended account is signed out before the door: 401, and not even its own channel',
            $this->describe($suspended),
        );
    }

    /* ---- STEP 12: the handsets ----------------------------------------------------------------- */

    private function guardDoor(Tenant $a, Tenant $b, Guard $guard): void
    {
        $ta = (string) $a->getTenantKey();
        $tb = (string) $b->getTenantKey();
        $token = $guard->createToken('isolation-probe', AppMatrix::abilitiesFor(AppMatrix::GUARD));
        $wire = static fn (string $name): string => ChannelName::WIRE_PREFIX.$name;
        $own = Channels::guard($ta, $guard->id);

        $control = $this->api($token->plainTextToken, 'live/auth', [$wire($own)]);
        $signed = $this->signedFor($control, $wire($own), 'g-'.$guard->id, batch: false);
        $this->check($signed, "CONTROL - guard {$guard->id} of {$ta} is granted {$own}, signed with the reverb key", $this->describe($control));

        if ($signed) {
            $this->refusedInArray($token->plainTextToken, [
                $wire("estate.{$tb}.guards") => 'another estate\'s guards channel',
                $wire("estate.{$tb}.passes") => 'another estate\'s revoked-pass feed',
                $wire("guard.{$tb}.{$guard->id}") => 'its own id under another estate',
                $wire("guard.{$ta}.".self::NOBODY) => 'another guard\'s own channel',
                $wire("estate.{$ta}.security") => 'the security feed, which names who pressed',
                $wire("household.{$ta}.1") => 'a household channel',
                $wire('user.'.$guard->id) => 'a console user\'s channel',
                $wire("community.{$ta}") => 'the residents\' community channel',
            ], "guard {$guard->id}");

            $caught = $this->json($this->apiRaw($token->plainTextToken, 'POST', 'live/catch-up', ['channels' => [
                ['name' => $own, 'c' => null, 'e' => null], ['name' => "estate.{$ta}.security", 'c' => 0, 'e' => 0], ['name' => "estate.{$tb}.passes", 'e' => 0],
            ]]));
            $statuses = array_column((array) ($caught['channels'] ?? []), 'status', 'name');
            $this->check(
                ($statuses[$own] ?? null) === 'ok' && ($statuses["estate.{$ta}.security"] ?? null) === 'refused' && ($statuses["estate.{$tb}.passes"] ?? null) === 'refused',
                'STEP 13 - a guard\'s catch-up answers its own channel and refuses the security feed and another estate\'s passes',
                (string) json_encode($statuses),
            );
        } else {
            $this->skip('STEP 12 - every guard refusal', 'the positive control failed');
        }

        // A guard reassigned to B keeps no channel of A; a suspended guard's handset reaches nothing.
        $guard->forceFill(['tenant_id' => $tb])->saveQuietly();
        $moved = $this->api($token->plainTextToken, 'live/auth', [$wire($own)]);
        $this->check($this->refusedIn($moved, $wire($own)), "a guard reassigned to {$tb} is refused its old channel under {$ta}", $this->describe($moved));
        $guard->forceFill(['tenant_id' => $ta, 'status' => 'suspended'])->saveQuietly();
        $idle = $this->api($token->plainTextToken, 'live/auth', [$wire($own)]);
        $this->check($idle['status'] === 403, 'a suspended guard\'s handset is refused at the door: 403', $this->describe($idle));

        $token->accessToken->delete();
        $gone = $this->api($token->plainTextToken, 'live/auth', [$wire($own)]);
        $this->check($gone['status'] === 401, 'a deleted token is 401 and grants nothing', $this->describe($gone));
    }

    private function residentDoor(Tenant $a, Tenant $b, ResidentAccount $resident): void
    {
        $ta = (string) $a->getTenantKey();
        $tb = (string) $b->getTenantKey();
        $wire = static fn (string $name): string => ChannelName::WIRE_PREFIX.$name;
        $token = $resident->createToken('isolation-probe', AppMatrix::abilitiesFor(AppMatrix::RESIDENT));
        $own = Channels::resident($ta, $resident->id);

        $control = $this->api($token->plainTextToken, 'live/auth', [$wire($own)]);
        $signed = $this->signedFor($control, $wire($own), 'r-'.$resident->id, batch: false);
        $this->check($signed, "CONTROL - resident account {$resident->id} of {$ta} is granted {$own}, signed with the reverb key", $this->describe($control));

        $config = $this->json($this->apiRaw($token->plainTextToken, 'GET', 'live/config', []));
        $household = collect((array) ($config['channels'] ?? []))->pluck('name')->first(static fn ($n): bool => is_string($n) && str_starts_with($n, 'household.'));

        if ($signed) {
            $rows = [
                $wire("resident.{$ta}.".self::NOBODY) => 'another account\'s own channel',
                $wire("household.{$ta}.".self::NOBODY) => 'another household',
                $wire("household.{$tb}.1") => 'a household of another estate',
                $wire("estate.{$ta}.passes") => 'the guards\' revoked-pass feed',
                $wire("estate.{$ta}.security") => 'the security feed',
                $wire("community.{$tb}") => 'another estate\'s community',
            ];

            if (is_string($household)) {
                $rows[$wire(str_replace(".{$ta}.", ".{$tb}.", $household))] = 'its own household id under another estate';
            }

            $this->refusedInArray($token->plainTextToken, $rows, "resident account {$resident->id}");
        } else {
            $this->skip('STEP 12 - every resident refusal', 'the positive control failed');
        }

        $resident->forceFill(['status' => ResidentAccount::SUSPENDED])->saveQuietly();
        $suspended = $this->api($token->plainTextToken, 'live/auth', [$wire($own)]);
        $this->check($suspended['status'] === 403, 'a suspended resident account is refused at the door: 403', $this->describe($suspended));

        $resident->forceFill(['status' => ResidentAccount::REVOKED])->saveQuietly();
        $revoked = $this->api($token->plainTextToken, 'live/auth', [$wire($own)]);
        $this->check($revoked['status'] === 403, 'a revoked resident account is refused at the door: 403', $this->describe($revoked));

        $token->accessToken->delete();
    }

    /** The pending-resident row of section 3.7, on the grant and on catch-up, in one request each. */
    private function pendingDoor(Tenant $a): void
    {
        $ta = (string) $a->getTenantKey();
        $wire = static fn (string $name): string => ChannelName::WIRE_PREFIX.$name;

        $pending = ResidentAccount::query()->create([
            'tenant_id' => $ta,
            'channel' => 'email',
            'destination' => 'live-probe-pending-'.bin2hex(random_bytes(4)).'@probe.invalid',
            'full_name' => 'Isolation probe (pending)',
            'status' => ResidentAccount::PENDING,
            'verified_at' => now(),
            'claim_id' => self::NOBODY,
        ]);
        $token = $pending->createToken('isolation-probe', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

        $own = Channels::resident($ta, $pending->id);
        $claim = Channels::claim($ta, self::NOBODY);
        $household = Channels::household($ta, 1);

        $granted = $this->api($token, 'live/auth', [$wire($own), $wire($claim), $wire($household), $wire(Channels::community($ta))]);
        $this->check(
            $this->signedFor($granted, $wire($own), 'r-'.$pending->id, batch: false)
                && $this->signedFor($granted, $wire($claim), 'r-'.$pending->id, batch: false)
                && $this->refusedIn($granted, $wire($household))
                && $this->refusedIn($granted, $wire(Channels::community($ta))),
            'CONTROL + refusal - a pending account is granted its own channel and its claim\'s, and refused the household and the community',
            $this->describe($granted),
        );

        $caught = $this->json($this->apiRaw($token, 'POST', 'live/catch-up', ['channels' => [
            ['name' => $own, 'c' => null, 'e' => null], ['name' => $claim, 'e' => null], ['name' => $household, 'c' => 0, 'e' => 0],
            ['name' => Channels::community($ta), 'e' => 0], ['name' => Channels::phase($ta, 'Probe block'), 'e' => 0],
        ]]));
        $statuses = array_column((array) ($caught['channels'] ?? []), 'status', 'name');

        $this->check(
            ($statuses[$own] ?? null) === 'ok' && ($statuses[$claim] ?? null) === 'ok'
                && ($statuses[$household] ?? null) === 'refused'
                && ($statuses[Channels::community($ta)] ?? null) === 'refused'
                && ($statuses[Channels::phase($ta, 'Probe block')] ?? null) === 'refused',
            'STEP 13 - a pending account\'s catch-up is ok for resident.* and claim.*, refused for household.*, community.* and phase.*, in one request',
            (string) json_encode($statuses),
        );
    }

    /* ---- the doors, in process ----------------------------------------------------------------- */

    /**
     * @param  array<string, mixed>  $json
     * @return array{status: int, body: string}
     */
    private function web(?User $user, Tenant $host, string $method, string $path, array $json): array
    {
        app('auth')->forgetGuards();

        $domain = $host->getTenantKey().'.'.config('app.estate_domain');

        if ($user !== null) {
            Auth::guard('web')->login($user);
            // A console session made at this host's own door (M59): the door lets it in, and what follows is judged.
            BindSessionToItsDoor::madeAt($domain);
        }

        try {
            $response = app(HttpKernel::class)->handle(Request::create(
                "http://{$domain}/{$path}",
                $method,
                [],
                [],
                [],
                ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_SEC_FETCH_SITE' => 'same-origin'],
                (string) json_encode($json),
            ));
        } finally {
            Auth::guard('web')->logout();
            tenancy()->end();
        }

        return ['status' => $response->getStatusCode(), 'body' => (string) $response->getContent()];
    }

    /**
     * @param  list<string>  $wires
     * @return array{status: int, body: string}
     */
    private function webAuth(User $user, Tenant $host, array $wires): array
    {
        return $this->web($user, $host, 'POST', 'live/auth', ['socket_id' => self::SOCKET, 'channel_names' => $wires]);
    }

    /** @return array{status: int, body: string} */
    private function webAuthOne(User $user, Tenant $host, string $wire): array
    {
        return $this->web($user, $host, 'POST', 'live/auth', ['socket_id' => self::SOCKET, 'channel_name' => $wire]);
    }

    /**
     * @param  list<string>  $wires
     * @return array{status: int, body: string}
     */
    private function api(string $token, string $path, array $wires): array
    {
        return $this->apiRaw($token, 'POST', $path, ['socket_id' => self::SOCKET, 'channel_names' => $wires]);
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array{status: int, body: string}
     */
    private function apiRaw(string $token, string $method, string $path, array $json): array
    {
        app('auth')->forgetGuards();

        $host = (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');

        try {
            $response = app(HttpKernel::class)->handle(Request::create(
                "http://{$host}/api/v1/{$path}",
                $method,
                $method === 'GET' ? $json : [],
                [],
                [],
                ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token],
                $method === 'GET' ? null : (string) json_encode($json),
            ));
        } finally {
            app('auth')->forgetGuards();
            tenancy()->end();
        }

        return ['status' => $response->getStatusCode(), 'body' => (string) $response->getContent()];
    }

    /* ---- judging an answer ---------------------------------------------------------------------- */

    /**
     * Whether the answer grants this wire name with a TRUE signature for this principal.
     *
     * @param  array{status: int, body: string}  $answer
     */
    private function signedFor(array $answer, string $wire, string $principal, bool $batch): bool
    {
        if ($answer['status'] !== 200) {
            return false;
        }

        $body = $this->json($answer);
        $grant = null;

        if ($batch) {
            $grant = $body['channels'][$wire] ?? null;
        } else {
            foreach ((array) ($body['channels'] ?? []) as $item) {
                if (is_array($item) && ($item['name'] ?? null) === $wire && ($item['refused'] ?? true) === false) {
                    $grant = $item;
                }
            }
        }

        if (! is_array($grant)) {
            return false;
        }

        $data = json_encode(['user_id' => $principal]);
        $key = (string) config('broadcasting.connections.reverb.key');
        $secret = (string) config('broadcasting.connections.reverb.secret');

        return ($grant['channel_data'] ?? null) === $data
            && ($grant['auth'] ?? null) === $key.':'.hash_hmac('sha256', self::SOCKET.':'.$wire.':'.$data, $secret);
    }

    /** @param  array{status: int, body: string}  $answer */
    private function refusedIn(array $answer, string $wire): bool
    {
        if ($answer['status'] !== 200) {
            return false;
        }

        $body = $this->json($answer);

        if (isset($body['refused']) && is_array($body['refused'])) {
            return in_array($wire, $body['refused'], true) && ! isset($body['channels'][$wire]);
        }

        foreach ((array) ($body['channels'] ?? []) as $item) {
            if (is_array($item) && ($item['name'] ?? null) === $wire) {
                return ($item['refused'] ?? false) === true && ($item['auth'] ?? null) === null;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $rows  wire name => what it is
     */
    private function refusedInBatch(User $user, Tenant $host, array $rows, string $where): void
    {
        $answer = $this->webAuth($user, $host, array_keys($rows));

        foreach ($rows as $wire => $what) {
            $this->refusal($answer, $wire, "{$what} ({$wire}), on {$where}");
        }
    }

    /**
     * @param  array<string, string>  $rows
     */
    private function refusedInArray(string $token, array $rows, string $who): void
    {
        $answer = $this->api($token, 'live/auth', array_keys($rows));

        foreach ($rows as $wire => $what) {
            $this->refusal($answer, $wire, "{$who}: {$what} ({$wire})");
        }
    }

    /** @param  array{status: int, body: string}  $answer */
    private function refusal(array $answer, string $wire, string $what, bool $single = false): void
    {
        if ($single) {
            $leak = $answer['status'] === 200;
            $held = $answer['status'] === 403;
        } else {
            $body = $this->json($answer);
            $leak = $answer['status'] === 200 && (isset($body['channels'][$wire]) || collect((array) ($body['channels'] ?? []))->contains(static fn ($item) => is_array($item) && ($item['name'] ?? null) === $wire && ($item['refused'] ?? true) === false));
            $held = $this->refusedIn($answer, $wire);
        }

        if ($leak) {
            $this->results[] = ['status' => 'fail', 'proves' => "refused: {$what}", 'detail' => 'LEAK - it was granted'];

            return;
        }

        $this->check($held, "refused: {$what}", 'no refusal to judge by - '.$this->describe($answer));
    }

    /**
     * @param  array{status: int, body: string}  $answer
     * @return array<string, mixed>
     */
    private function json(array $answer): array
    {
        $decoded = json_decode($answer['body'], true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param  array{status: int, body: string}  $answer */
    private function describe(array $answer): string
    {
        return 'HTTP '.$answer['status'].' '.substr(preg_replace('/\s+/', ' ', $answer['body']) ?? '', 0, 160);
    }

    private function check(bool $held, string $proves, string $detail): void
    {
        $this->results[] = ['status' => $held ? 'pass' : 'fail', 'proves' => $proves, 'detail' => $held ? '' : $detail];
    }

    private function fail(string $proves, string $detail): void
    {
        $this->results[] = ['status' => 'fail', 'proves' => $proves, 'detail' => $detail];
    }

    private function skip(string $proves, string $detail): void
    {
        $this->results[] = ['status' => 'skip', 'proves' => $proves, 'detail' => $detail];
    }
}
