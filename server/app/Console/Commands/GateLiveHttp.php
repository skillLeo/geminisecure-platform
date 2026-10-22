<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Api\AppMatrix;
use App\Enums\Console;
use App\Http\Middleware\BindSessionToItsDoor;
use App\Live\Channels;
use App\Live\Live;
use App\Models\EstateAssignment;
use App\Models\Guard;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Console\Command;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 *   php artisan gate:live-http [--url=https://geminisl.com] [--estate=phoenixparkvillage1] [--other=demoestate]
 *
 * The live doors, asked over REAL HTTP, of a running server (DESIGN.md section 10.2): the same
 * questions gate:isolation asks in process, answered by nginx, PHP-FPM and the session store the
 * server really runs with.
 *
 * ITS PRINCIPALS ARE THROWAWAY ROWS, COMMITTED, THEN DELETED. The in-process probe's rolled-back
 * fixtures are invisible to another process, so these are real rows, each named `live-probe-<six
 * random characters>`, made under Live::muted() so no screen hears of them: a user who is
 * suspended, a Head of Security with no sites, an unverified committee member, a revoked resident
 * account, a guard reassigned to another estate, and an active user for the positive control.
 * They, their tokens, their sessions and their grants are removed in `finally`, and the run then
 * asserts none is left. A run killed half way leaves rows behind; the next run deletes every
 * leftover `live-probe-*` row FIRST, and says how many.
 *
 * Sessions are minted through the configured session handler (Redis in production) and sent as
 * the encrypted cookie the server expects; tokens through createToken. Exits non-zero on any
 * failure. Pure ASCII.
 */
class GateLiveHttp extends Command
{
    public const MARK = 'live-probe-';

    private const SOCKET = '5566.7788';

    protected $signature = 'gate:live-http
        {--url= : the server to ask; the configured app URL when left out}
        {--estate= : the estate whose channels are asked about (its subdomain); the first estate when left out}
        {--other= : a second estate, for the cross-estate rows; the next estate when left out}';

    protected $description = 'Ask the live doors of a running server, over real HTTP, what they refuse; with throwaway principals it always removes';

    private int $failures = 0;


    /** @var list<string> */
    private array $sessions = [];

    public function handle(): int
    {
        $leftover = $this->sweep();
        $this->line($leftover === 0 ? 'No live-probe rows were left from an earlier run.' : "Removed {$leftover} live-probe row(s) left by an earlier run that did not finish.");

        $estates = Tenant::estates()->map(static fn (Tenant $t): string => (string) $t->getTenantKey())->values()->all();
        $a = (string) ($this->option('estate') ?: ($estates[0] ?? ''));
        $b = (string) ($this->option('other') ?: (collect($estates)->first(static fn (string $key): bool => $key !== $a) ?? ''));

        if ($a === '' || $b === '' || $a === $b) {
            $this->line('<fg=red>FAIL</> two different estates are needed to probe across; found '.count($estates).'.');

            return self::FAILURE;
        }

        $url = rtrim((string) ($this->option('url') ?: config('app.url')), '/');
        $this->line("Asking {$url} about estate {$a}, across to {$b}.");

        try {
            Live::muted(fn () => $this->probe($url, $a, $b));
        } catch (Throwable $e) {
            $this->assert(false, 'the HTTP probe ran to the end', class_basename($e).': '.$e->getMessage());
        } finally {
            $this->cleanUp();
        }

        $this->assert($this->sweep() === 0, 'no live-probe row is left behind', 'rows were still there after the clean-up');

        $this->line('');
        $this->line($this->failures === 0 ? '<fg=green>LIVE HTTP GATE PASSED</>' : "<fg=red>LIVE HTTP GATE FAILED - {$this->failures} assertion(s) did not hold</>");

        return $this->failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function probe(string $url, string $a, string $b): void
    {
        $estateUrl = $this->estateUrl($url, $a);

        // CONTROL: an active user's own channel, truly signed.
        $active = $this->user('gemini', 'active');
        $answer = $this->web($url, $active, [$this->wire(Channels::user($active->id))]);
        $this->assert($this->signed($answer, $this->wire(Channels::user($active->id)), 'u-'.$active->id), 'CONTROL - an active user is granted their own channel over HTTP, signed with the reverb key', $this->say($answer));

        // A suspended user: the session is ended before the door.
        $suspended = $this->user('gemini', 'suspended');
        $answer = $this->web($url, $suspended, [$this->wire(Channels::user($suspended->id))]);
        $this->assert($answer['status'] === 401, 'a suspended user is signed out before the door: 401', $this->say($answer));

        // A Head of Security with no sites: no Gemini feed, fan-in or copy.
        $noSites = $this->user('gemini', 'active', Role::HEAD_OF_SECURITY);
        $answer = $this->web($url, $noSites, [$this->wire('gemini.dispatch'), $this->wire(Channels::geminiFeed($a, 'dispatch'))]);
        $this->assert($this->refused($answer, [$this->wire('gemini.dispatch'), $this->wire(Channels::geminiFeed($a, 'dispatch'))]), 'a Head of Security with no sites is refused the dispatch fan-in and every estate copy', $this->say($answer));

        // An unverified committee member: not even their estate's feeds, on their estate's host.
        $unverified = $this->user('estate', 'active', Role::PRESIDENT, $a, verified: false);
        $answer = $this->web($estateUrl, $unverified, [$this->wire(Channels::estateFeed($a, 'security'))]);
        $this->assert($this->refused($answer, [$this->wire(Channels::estateFeed($a, 'security'))]), 'an unverified committee member is refused the estate\'s feeds', $this->say($answer));

        // A revoked resident account: refused at the door.
        $revoked = $this->resident($a, ResidentAccount::REVOKED);
        $answer = $this->api($url, $revoked->createToken(self::MARK.'token', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken, [$this->wire(Channels::resident($a, $revoked->id))]);
        $this->assert($answer['status'] === 403, 'a revoked resident account is refused at the door: 403', $this->say($answer));

        // A guard reassigned to the other estate: nothing of the old one.
        $moved = $this->guard($b);
        $answer = $this->api($url, $moved->createToken(self::MARK.'token', AppMatrix::abilitiesFor(AppMatrix::GUARD))->plainTextToken, [
            $this->wire(Channels::guard($a, $moved->id)), $this->wire(Channels::estateGuards($a)), $this->wire(Channels::estatePasses($a)),
        ]);
        $this->assert($this->refused($answer, [$this->wire(Channels::guard($a, $moved->id)), $this->wire(Channels::estateGuards($a)), $this->wire(Channels::estatePasses($a))]), "a guard reassigned to {$b} is refused {$a}'s guard, guards and passes channels", $this->say($answer));
    }

    /* ---- throwaway principals ---------------------------------------------------------------- */

    private function user(string $console, string $status, ?string $role = null, ?string $estate = null, bool $verified = true): User
    {
        $name = self::MARK.Str::lower(Str::random(6));

        $user = new User;
        $user->forceFill([
            'name' => $name,
            'email' => $name.'@probe.invalid',
            'password' => Hash::make(Str::random(40)),
            'console' => $console === 'estate' ? Console::Estate->value : Console::Gemini->value,
            'status' => $status,
            'contact_email' => $name.'@probe.invalid',
            'contact_email_verified_at' => $verified ? now() : null,
        ])->save();

        if ($role !== null) {
            $user->syncRoles([$role]);
        }

        if ($estate !== null && $role !== null) {
            EstateAssignment::create([
                'user_id' => $user->id,
                'tenant_id' => $estate,
                'role_id' => Role::query()->where('name', $role)->whereNull('version_of_id')->firstOrFail()->id,
                'is_active' => true,
            ]);
        }

        return $user->fresh() ?? $user;
    }

    private function resident(string $estate, string $status): ResidentAccount
    {
        $name = self::MARK.Str::lower(Str::random(6));

        $account = ResidentAccount::query()->create([
            'tenant_id' => $estate, 'channel' => 'email', 'destination' => $name.'@probe.invalid', 'full_name' => $name,
            'status' => $status, 'verified_at' => now(),
        ]);

        return $account;
    }

    private function guard(string $estate): Guard
    {
        $name = self::MARK.Str::lower(Str::random(6));

        $guard = Guard::query()->create([
            'full_name' => $name, 'employee_number' => strtoupper($name), 'psra_number' => strtoupper($name),
            'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active', 'tenant_id' => $estate,
        ]);

        return $guard;
    }

    /** Everything this run made, and its sessions. Each step on its own, so one failure removes the rest. */
    private function cleanUp(): void
    {
        foreach ($this->sessions as $id) {
            try {
                app('session')->driver()->getHandler()->destroy($id);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->sessions = [];
        $this->sweep();
    }

    /** Delete every `live-probe-*` row there is (this run's or a killed one's). Returns how many. */
    private function sweep(): int
    {
        $db = DB::connection((string) config('tenancy.database.central_connection', 'mysql'));
        $like = self::MARK.'%';
        $removed = 0;

        $users = $db->table('users')->where('name', 'like', $like)->pluck('id')->all();
        $residents = $db->table('resident_accounts')->where('full_name', 'like', $like)->pluck('id')->all();
        $guards = $db->table('guards')->where('full_name', 'like', $like)->pluck('id')->all();

        $removed += $db->table('personal_access_tokens')->where('name', 'like', $like)->delete();
        $removed += $db->table('live_grants')->whereIn('principal', [
            ...array_map(static fn ($id): string => 'u-'.$id, $users),
            ...array_map(static fn ($id): string => 'r-'.$id, $residents),
            ...array_map(static fn ($id): string => 'g-'.$id, $guards),
        ])->delete();

        if ($users !== []) {
            $db->table('estate_assignments')->whereIn('user_id', $users)->delete();
            $db->table('model_has_roles')->where('model_type', (new User)->getMorphClass())->whereIn('model_id', $users)->delete();
            $removed += $db->table('users')->whereIn('id', $users)->delete();
        }

        $removed += $db->table('resident_accounts')->whereIn('id', $residents)->delete();
        $removed += $db->table('guards')->whereIn('id', $guards)->delete();

        return $removed;
    }

    /* ---- over HTTP ------------------------------------------------------------------------------ */

    /**
     * @param  list<string>  $wires
     * @return array{status: int, body: string}
     */
    private function web(string $base, User $user, array $wires): array
    {
        $host = (string) parse_url($base, PHP_URL_HOST);
        $cookie = $this->sessionCookie($user, $host);

        $response = Http::timeout(10)
            ->withHeaders(['Accept' => 'application/json', 'Sec-Fetch-Site' => 'same-origin', 'Origin' => $base])
            ->withCookies([(string) config('session.cookie') => $cookie], $host)
            ->post($base.'/live/auth', ['socket_id' => self::SOCKET, 'channel_names' => $wires]);

        return ['status' => $response->status(), 'body' => $response->body()];
    }

    /**
     * @param  list<string>  $wires
     * @return array{status: int, body: string}
     */
    private function api(string $base, string $token, array $wires): array
    {
        $response = Http::timeout(10)->acceptJson()->withToken($token)
            ->post($base.'/api/v1/live/auth', ['socket_id' => self::SOCKET, 'channel_names' => $wires]);

        return ['status' => $response->status(), 'body' => $response->body()];
    }

    /**
     * A signed-in session in the configured store, as the encrypted cookie the server reads.
     *
     * MADE AT THE DOOR IT IS SENT TO (D-231): a session is good only on the host that made it, and one carrying no door
     * is refused as one made before that rule ("Please sign in again."), which is what this gate's own sessions got
     * from 9 October until the full suite found it on 10 October.
     */
    private function sessionCookie(User $user, string $host): string
    {
        $guard = Auth::guard('web');

        if (! $guard instanceof SessionGuard) {
            throw new RuntimeException('The web guard is not a session guard, so there is no session to mint.');
        }

        $session = app('session')->driver();
        $session->setId(Str::random(40));
        $session->start();
        $session->put($guard->getName(), $user->getAuthIdentifier());
        $session->put('password_hash_web', $user->getAuthPassword());
        $session->put(BindSessionToItsDoor::DOOR, $host);
        $session->save();

        $this->sessions[] = $session->getId();

        $name = (string) config('session.cookie');
        $key = app('encrypter')->getKey();

        return app('encrypter')->encrypt(CookieValuePrefix::create($name, $key).$session->getId(), false);
    }

    private function estateUrl(string $base, string $estate): string
    {
        $scheme = (string) (parse_url($base, PHP_URL_SCHEME) ?: 'https');

        return $scheme.'://'.$estate.'.'.config('app.estate_domain');
    }

    private function wire(string $name): string
    {
        return 'private-live.'.$name;
    }

    /* ---- judging ---------------------------------------------------------------------------------- */

    /** @param  array{status: int, body: string}  $answer */
    private function signed(array $answer, string $wire, string $principal): bool
    {
        $body = json_decode($answer['body'], true);
        $grant = is_array($body) ? ($body['channels'][$wire] ?? null) : null;
        $data = json_encode(['user_id' => $principal]);

        return $answer['status'] === 200 && is_array($grant)
            && ($grant['channel_data'] ?? null) === $data
            && ($grant['auth'] ?? null) === config('broadcasting.connections.reverb.key').':'.hash_hmac('sha256', self::SOCKET.':'.$wire.':'.$data, (string) config('broadcasting.connections.reverb.secret'));
    }

    /**
     * Refused, in either door's batch form; a 200 that grants any of them is a LEAK.
     *
     * @param  array{status: int, body: string}  $answer
     * @param  list<string>  $wires
     */
    private function refused(array $answer, array $wires): bool
    {
        if ($answer['status'] !== 200) {
            return false;
        }

        $body = json_decode($answer['body'], true);

        if (! is_array($body)) {
            return false;
        }

        foreach ($wires as $wire) {
            $web = isset($body['refused']) && in_array($wire, (array) $body['refused'], true) && ! isset($body['channels'][$wire]);
            $api = collect((array) ($body['channels'] ?? []))->contains(static fn ($item): bool => is_array($item) && ($item['name'] ?? null) === $wire && ($item['refused'] ?? false) === true);

            if (! $web && ! $api) {
                return false;
            }
        }

        return true;
    }

    /** @param  array{status: int, body: string}  $answer */
    private function say(array $answer): string
    {
        return 'HTTP '.$answer['status'].' '.substr(preg_replace('/\s+/', ' ', $answer['body']) ?? '', 0, 160);
    }

    private function assert(bool $held, string $proves, string $detail): void
    {
        if ($held) {
            $this->line("  <fg=green>PASS</> {$proves}");

            return;
        }

        $this->failures++;
        $this->line("  <fg=red>FAIL</> {$proves} - {$detail}");
    }
}
