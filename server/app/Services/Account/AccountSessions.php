<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Models\AccountSession;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\SiteTime;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * WHERE A CONSOLE ACCOUNT IS SIGNED IN, AND SIGNING IT OUT (M32 F9).
 *
 * TWO MECHANISMS, BECAUSE PRODUCTION KEEPS SESSIONS IN REDIS. Nothing there finds "every session of user 42", so:
 *
 *   THE GENERATION (`users.sign_in_generation`) ends sessions wherever they are kept. A session carries the number its
 *   account had when it was made; "Sign out everywhere else" and a password change raise the account's, and every
 *   other session ends at its next request (`KeepAccountSession`), including one made before this was built. The user
 *   row is read on every authenticated request already, so this costs no query.
 *
 *   THE LIST (`account_sessions`) is what the page shows and what "Sign it out" ends one of. A row is written on the
 *   first request after a real sign-in (the session itself carries the request to write it, which survives the
 *   session's id being regenerated at sign-in), touched at most every fifteen minutes, and deleted when the session is
 *   signed out. So an ordinary page costs no query here either.
 */
final class AccountSessions
{
    /** Set in the session at sign-in: write this session's row on its next request. */
    public const RECORD = 'account.record';

    public const ROW = 'account.session_row';

    public const TOUCHED = 'account.touched_at';

    public const GENERATION = 'account.generation';

    /** How often a row's "last seen" is brought up to date, at most. */
    private const TOUCH_EVERY_SECONDS = 900;

    public function __construct(private readonly AuditLogger $audit) {}

    /** At sign-in: the session starts at the account's generation, and is written to the list on its next request. */
    public function signedIn(Session $session, User $user): void
    {
        $session->put(self::RECORD, true);
        $session->put(self::GENERATION, (int) $user->sign_in_generation);
        $session->forget([self::ROW, self::TOUCHED]);
    }

    /**
     * On every request of a signed-in console account: whether this session may go on. False when the account was
     * signed out everywhere else, from another session, after this one was made.
     */
    public function keep(Request $request, User $user): bool
    {
        $session = $request->session();
        $generation = $session->get(self::GENERATION);

        if ($generation === null) {
            // A session from before this existed: it starts at the account's number now.
            $session->put(self::GENERATION, (int) $user->sign_in_generation);
        } elseif ((int) $generation !== (int) $user->sign_in_generation) {
            return false;
        }

        if ($session->pull(self::RECORD) === true) {
            $row = AccountSession::query()->updateOrCreate(
                ['session_hash' => AccountSession::hashOf($session->getId())],
                ['user_id' => $user->id, 'session_key' => $session->getId(), 'console' => $user->console->value, ...$this->from($request), 'last_seen_at' => Carbon::now()],
            );
            $session->put(self::ROW, $row->id);
            $session->put(self::TOUCHED, Carbon::now()->getTimestamp());

            return true;
        }

        $rowId = $session->get(self::ROW);

        if ($rowId !== null && (int) $session->get(self::TOUCHED, 0) < Carbon::now()->getTimestamp() - self::TOUCH_EVERY_SECONDS) {
            $row = AccountSession::query()->whereKey((int) $rowId)->where('user_id', $user->id)->first();

            // The id is the session's own now, should it have been regenerated since the row was written.
            $row?->forceFill(['session_hash' => AccountSession::hashOf($session->getId()), 'session_key' => $session->getId(), ...$this->from($request), 'last_seen_at' => Carbon::now()])->save();
            $session->put(self::TOUCHED, Carbon::now()->getTimestamp());
        }

        return true;
    }

    /**
     * The places this account is signed in, this one first, then the most recently seen. A row older than the
     * session lifetime is a session that has ended by itself, and goes.
     *
     * @return list<array{id: int|null, device: string, address: string|null, seen: string, current: bool}>
     */
    public function listFor(User $user, Request $request): array
    {
        $expired = Carbon::now()->subMinutes((int) config('session.lifetime', 120));
        AccountSession::query()->where('user_id', $user->id)->where('last_seen_at', '<', $expired)->delete();

        $current = $request->session()->get(self::ROW);
        $rows = AccountSession::query()->where('user_id', $user->id)->orderByDesc('last_seen_at')->orderByDesc('id')->get();

        $list = [];
        $seenCurrent = false;

        foreach ($rows as $row) {
            $isCurrent = $current !== null && (int) $current === (int) $row->id;
            $seenCurrent = $seenCurrent || $isCurrent;

            $list[] = [
                'id' => $isCurrent ? null : (int) $row->id,
                'device' => self::device($row->user_agent),
                'address' => $row->ip_address,
                'seen' => $isCurrent ? 'now' : self::ago($row->last_seen_at),
                'current' => $isCurrent,
            ];
        }

        // A session from before the list existed is still this device.
        if (! $seenCurrent) {
            array_unshift($list, ['id' => null, 'device' => self::device($request->userAgent()), 'address' => $request->ip(), 'seen' => 'now', 'current' => true]);
        }

        usort($list, static fn (array $a, array $b): int => (int) $b['current'] <=> (int) $a['current']);

        return $list;
    }

    /** End one other session of this account. False when it is not one of theirs, or it is this one. */
    public function signOut(User $user, int $rowId, Request $request): bool
    {
        $row = AccountSession::query()->whereKey($rowId)->where('user_id', $user->id)->first();

        if (! $row instanceof AccountSession || (int) $request->session()->get(self::ROW) === (int) $row->id) {
            return false;
        }

        $this->destroy($row);
        $this->audit->record(action: 'account.session_signed_out', entityType: 'User', entityId: (string) $user->id, after: ['device' => self::device($row->user_agent), 'address' => $row->ip_address]);

        return true;
    }

    /**
     * End every session of this account but this one: the generation is raised, so a session not on the list ends
     * too, and every listed one is ended now. Returns how many were listed.
     */
    public function signOutOthers(User $user, Request $request, string $why = 'signed_out_elsewhere'): int
    {
        $user->forceFill(['sign_in_generation' => (int) $user->sign_in_generation + 1])->save();
        $request->session()->put(self::GENERATION, (int) $user->sign_in_generation);

        $current = $request->session()->get(self::ROW);
        $others = AccountSession::query()->where('user_id', $user->id)
            ->when($current !== null, static fn ($q) => $q->whereKeyNot((int) $current))
            ->get();

        foreach ($others as $row) {
            $this->destroy($row);
        }

        if ($why === 'signed_out_elsewhere') {
            $this->audit->record(action: 'account.signed_out_elsewhere', entityType: 'User', entityId: (string) $user->id, after: ['sessions' => $others->count()]);
        }

        return $others->count();
    }

    /** This session's own row, at sign-out. */
    public function forget(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $row = $request->session()->get(self::ROW);

        if ($row !== null) {
            AccountSession::query()->whereKey((int) $row)->delete();
        }
    }

    /** "Chrome on Windows", read from the browser's own description of itself. */
    public static function device(?string $agent): string
    {
        $agent = (string) $agent;

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') || str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') || str_contains($agent, 'CriOS/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'A browser',
        };

        $system = match (true) {
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iPhone or iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS X') || str_contains($agent, 'Macintosh') => 'a Mac',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return $system === null ? $browser : $browser.' on '.$system;
    }

    private static function ago(Carbon $at): string
    {
        $minutes = (int) $at->diffInMinutes(Carbon::now(), absolute: true);

        return match (true) {
            $minutes < 2 => 'now',
            $minutes < 60 => $minutes.' minutes ago',
            $minutes < 120 => '1 hour ago',
            $minutes < 1440 => intdiv($minutes, 60).' hours ago',
            default => SiteTime::stamp($at, null, 'j M, g:i A'),
        };
    }

    /** @return array{user_agent: string|null, ip_address: string|null} */
    private function from(Request $request): array
    {
        return ['user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null, 'ip_address' => $request->ip()];
    }

    /** The session ended where it is kept, and its row gone. */
    private function destroy(AccountSession $row): void
    {
        try {
            app('session')->driver()->getHandler()->destroy((string) $row->session_key);
        } catch (Throwable) {
            // A key that cannot be read back (the application key changed) is a session that cannot be found either.
        }

        $row->delete();
    }
}
