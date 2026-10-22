<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\Console;
use App\Http\Middleware\EndSessionOfInactiveAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\ConsoleHome;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * ONE FRONT DOOR (M11 §5.2): somebody who proved who they are on the central host is taken to their estate
 * signed in — by a token that estate redeems once, within 60 seconds, to create a session of its OWN.
 *
 * NO COOKIE CROSSES A HOSTNAME. The session cookie is host-only (`SESSION_DOMAIN` is null) and stays so; the
 * central host never holds a session for an estate account. That is what broke the client's meeting on
 * 23 September 2026: a session made on the central host and expected on the estate's, where the cookie does
 * not travel (App\Http\Middleware\RedirectToOwnConsole records it).
 *
 * THE TOKEN: 64 random characters, in ONE redirect and nowhere else. Only its SHA-256 is stored, bound to one
 * account and one estate; it lives 60 seconds; `used_at` is claimed by one conditional UPDATE, so a replay, or
 * two tabs racing, can never both win. Every issue, redemption and refusal is written to the audit log with
 * who, which estate and from which address.
 */
final class FrontDoor
{
    public const TTL_SECONDS = 60;

    /** The token as it appears in a URL (and the route constraint). */
    public const TOKEN_PATTERN = '[A-Za-z0-9]{64}';

    private const TABLE = 'front_door_tokens';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The estates this account may be taken to, by name. Every status: `dunning` and `suspended` are billing
     * states that never gate entry (App\Services\Gemini\ClientDirectory::STATUS_FILTERS), so the front door
     * takes a member there exactly as the estate's own door would let them in.
     *
     * @return list<array{id: string, name: string}>
     */
    public function estatesOf(User $user): array
    {
        $ids = array_values(array_unique(array_map('strval', $user->accessibleEstateIds())));

        if ($ids === []) {
            return [];
        }

        $estates = [];

        foreach (Tenant::query()->whereIn('id', $ids)->orderBy('name')->toBase()->get(['id', 'name']) as $row) {
            $estates[] = ['id' => (string) $row->id, 'name' => (string) $row->name];
        }

        return $estates;
    }

    /** A new single-use token for this account at this estate. The raw token is returned once and never stored. */
    public function issue(User $user, string $tenantId, Request $request): string
    {
        if ($user->console !== Console::Estate || ! $user->canAccessEstate($tenantId)) {
            throw new LogicException("The front door issued a token to user {$user->id} for {$tenantId}, which that account does not hold.");
        }

        $token = Str::random(64);
        $now = Carbon::now();

        $this->central()->table(self::TABLE)->insert([
            'token_hash' => hash('sha256', $token),
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'issued_ip' => $request->ip(),
            'expires_at' => $now->copy()->addSeconds(self::TTL_SECONDS),
            'created_at' => $now,
        ]);

        $this->audit->record('session.front_door_issued', 'User', (string) $user->id, null, ['estate' => $tenantId], $tenantId, $user);

        return $token;
    }

    /** Where the estate redeems it: its own host in production, its path on the one local host. */
    public function redeemUrl(string $tenantId, string $token): string
    {
        return ConsoleHome::estateUrl($tenantId).'/front-door/'.$token;
    }

    /**
     * Sends a proven account to where it belongs: an estate account with one estate is handed over at once,
     * with several it chooses first. Nothing here signs anybody in on THIS host.
     */
    public function handOff(User $user, Request $request): RedirectResponse
    {
        $estates = $this->estatesOf($user);

        if ($estates === []) {
            return redirect()->route('front-door.show')->withErrors([
                'email' => 'This account belongs to an estate console, and it holds no estate. The estate office can restore its assignment.',
            ]);
        }

        if (count($estates) > 1) {
            $request->session()->put('front_door.choosing', ['user' => $user->id, 'until' => Carbon::now()->addMinutes(5)->getTimestamp()]);

            return redirect()->route('front-door.choose');
        }

        return $this->away($user, $estates[0]['id'], $request);
    }

    /** The redirect that carries the token to the estate, once. */
    public function away(User $user, string $tenantId, Request $request): RedirectResponse
    {
        $token = $this->issue($user, $tenantId, $request);

        return redirect()->away($this->redeemUrl($tenantId, $token), 303)->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * The account a token names, at the estate it was issued for — or null. Claims the token in the same
     * statement that checks it, so it is spent whatever happens next. Every refusal is audited.
     */
    public function redeem(string $token, string $tenantId, Request $request): ?User
    {
        $hash = hash('sha256', $token);
        $central = $this->central();
        $row = $central->table(self::TABLE)->where('token_hash', $hash)->first(['id', 'user_id', 'tenant_id']);

        if ($row === null) {
            return $this->refuse('unknown', null, $tenantId);
        }

        if ((string) $row->tenant_id !== $tenantId) {
            // A token for estate A presented at estate B: B never honours it, and A's is spent too.
            $central->table(self::TABLE)->where('id', $row->id)->whereNull('used_at')->update(['used_at' => Carbon::now(), 'used_ip' => $request->ip()]);

            return $this->refuse('another estate', (int) $row->user_id, $tenantId);
        }

        $claimed = $central->table(self::TABLE)
            ->where('id', $row->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', Carbon::now())
            ->update(['used_at' => Carbon::now(), 'used_ip' => $request->ip()]);

        if ($claimed !== 1) {
            return $this->refuse('used or expired', (int) $row->user_id, $tenantId);
        }

        $user = User::query()->find($row->user_id);

        if (! $user instanceof User || EndSessionOfInactiveAccount::refusal($user) !== null || ! $user->canAccessEstate($tenantId)) {
            return $this->refuse('account no longer admitted', (int) $row->user_id, $tenantId);
        }

        $this->audit->record('session.front_door_redeemed', 'User', (string) $user->id, null, ['estate' => $tenantId], $tenantId, $user);

        return $user;
    }

    /** Tokens a day old or more, used or not: nothing will ever redeem them. */
    public function prune(): int
    {
        return $this->central()
            ->table(self::TABLE)
            ->where('created_at', '<', Carbon::now()->subDay())
            ->delete();
    }

    private function central(): ConnectionInterface
    {
        return DB::connection((string) config('tenancy.database.central_connection', 'mysql'));
    }

    private function refuse(string $why, ?int $userId, string $tenantId): null
    {
        $user = $userId === null ? null : User::query()->find($userId);

        $this->audit->record('session.front_door_refused', 'User', $userId === null ? null : (string) $userId, null, ['estate' => $tenantId, 'why' => $why], $tenantId, $user instanceof User ? $user : null);

        return null;
    }
}
