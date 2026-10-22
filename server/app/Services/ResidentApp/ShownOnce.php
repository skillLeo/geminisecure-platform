<?php

declare(strict_types=1);

namespace App\Services\ResidentApp;

use App\Models\ResidentAccount;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SHOWN ONCE, FOR THE ACCOUNT (M47 §3).
 *
 * Something the Resident App shows a person once and never again — today the first-run tour — is recorded HERE, on the
 * account, not on the phone. The phone's record went with every sign-out the person chose, every reinstall and every
 * new phone, so the tour came back at every sign-in. On the account it is shown on the first ever sign-in and never
 * again: not after signing out and in, not after reinstalling, not on another phone.
 *
 * Finishing it and skipping it both count. The first time stands: a second phone marking it later changes nothing, and
 * two phones marking it at once leave one answer (the row is locked while it is written).
 */
final class ShownOnce
{
    /** Everything shown once, by the name the app and `me.shown` use. */
    public const KEYS = ['tour'];

    /**
     * What this account has been shown: every key, with when it was first finished or skipped, or null.
     *
     * @return array<string, string|null>
     */
    public static function shape(ResidentAccount $account): array
    {
        $shown = self::decode($account->getAttribute('shown'));

        return array_combine(self::KEYS, array_map(static fn (string $key): ?string => $shown[$key] ?? null, self::KEYS));
    }

    /**
     * Record that the account has been shown this, unless it already was.
     *
     * @return array<string, string|null> what the account has been shown, this included
     */
    public function mark(ResidentAccount $account, string $key): array
    {
        DB::connection('mysql')->transaction(static function () use ($account, $key): void {
            $row = DB::connection('mysql')->table('resident_accounts')->where('id', $account->id)->lockForUpdate()->first(['shown']);
            $shown = self::decode($row?->shown);

            if (isset($shown[$key])) {
                return;
            }

            $shown[$key] = (string) SiteTime::iso(Carbon::now());

            DB::connection('mysql')->table('resident_accounts')->where('id', $account->id)->update(['shown' => json_encode($shown), 'updated_at' => Carbon::now()]);
        });

        return self::shape($account->refresh());
    }

    /** @return array<string, string> */
    private static function decode(mixed $stored): array
    {
        $value = is_string($stored) ? json_decode($stored, true) : $stored;

        return is_array($value) ? array_filter($value, static fn (mixed $at, mixed $key): bool => is_string($key) && is_string($at), ARRAY_FILTER_USE_BOTH) : [];
    }
}
