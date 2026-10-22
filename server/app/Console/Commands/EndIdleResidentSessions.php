<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Api\ApiError;
use App\Models\PersonalAccessToken;
use App\Models\ResidentAccount;
use App\Services\Reviewer\ReviewerMode;
use App\Services\ResidentApp\ResidentSessions;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 *   php artisan resident-sessions:end-idle             every Resident App sign-in unused for 30 days, ended
 *   php artisan resident-sessions:end-idle --days=45   another limit
 *
 * A SIGN-IN NOBODY HAS USED FOR A MONTH IS ENDED (the user, 8 Oct 2026, M54 §2: "drop anything unused for 30 days").
 * Security → Active sessions listed every sign-in ever made, and a timed-out one stays listed on purpose, because its
 * phone still gets the household's notifications (ResidentSessions). A phone silent for thirty days is one sold, lost,
 * reset or an old install: it is ended here exactly as "Sign out" on another phone ends it — the token, the push
 * address and the fingerprint key, each one on the audit log — so it leaves the list because it is gone, never because
 * a list hides it. The demonstration account is not touched: its sign-ins are other people's.
 */
class EndIdleResidentSessions extends Command
{
    protected $signature = 'resident-sessions:end-idle {--days=30 : how long a sign-in may go unused}';

    protected $description = 'End every Resident App sign-in unused for 30 days, as Sign out does, audited';

    public function handle(ResidentSessions $sessions): int
    {
        $days = max(1, (int) $this->option('days'));
        $before = Carbon::now()->subDays($days);
        $ended = 0;

        PersonalAccessToken::query()
            ->where('tokenable_type', (new ResidentAccount)->getMorphClass())
            ->where('name', 'like', ResidentSessions::TOKEN_PREFIX.'%')
            ->where(static fn ($idle) => $idle
                ->where('last_used_at', '<', $before)
                ->orWhere(static fn ($never) => $never->whereNull('last_used_at')->where('created_at', '<', $before)))
            ->orderBy('id')
            ->chunkById(200, function ($tokens) use ($sessions, $days, &$ended): void {
                foreach ($tokens as $token) {
                    $account = ResidentAccount::query()->find($token->tokenable_id);

                    if (! $account instanceof ResidentAccount || ReviewerMode::isReviewer($account)) {
                        continue;
                    }

                    try {
                        $sessions->end($account, (int) $token->id, null, "unused for {$days} days");
                        $ended++;
                    } catch (ApiError) {
                        // Ended a moment ago by the person themselves: nothing left to end.
                    }
                }
            });

        $this->info("Ended {$ended} sign-in".($ended === 1 ? '' : 's')." unused for {$days} days.");

        return self::SUCCESS;
    }
}
