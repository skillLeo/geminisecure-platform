<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Models\ResidentAccount;
use App\Models\Tenant;
use App\Notifications\ResidentNewsEmailed;
use App\Support\CentralQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * "EMAIL" ON BOARD 20 — THE SAME NEWS A PUSH CARRIES, BY EMAIL (M50 §4.2 #4).
 *
 * Called by `PushNotifier` for every Resident App push it decides, with the accounts it was for — before any phone is
 * looked up, so a resident with no phone registered (notifications refused, a tablet, no app open since) still gets the
 * email. Who is emailed:
 *
 *   - the account turned Email ON itself (`notify_email`, off by default — nobody is emailed by a default);
 *   - it signs in by email (`channel`), so the address is one its one-time code proved — never one typed by somebody
 *     else, never a sample resident's;
 *   - it is not suspended, revoked or deleted;
 *   - the category is not one it switched off (`notify_email_muted`, sent with the switch). The types with no
 *     category (panic, a household claim approved) cannot be switched off here either;
 *   - NOT "somebody at the gate" (`approval_request`): it is answered in the app within minutes, and an email about a
 *     visitor long gone is noise;
 *   - once per (type, record) inside the push's own coalescing window, like the push: six changes to one ticket are
 *     one email.
 *
 * NEVER THROWS, like every push: an email is a courtesy on top of something that has already happened.
 */
class ResidentEmails
{
    /** The kinds of push no email follows. */
    private const NOT_EMAILED = ['approval_request'];

    /** The coalescing key's principal type: an email's window is its own, never a push's. */
    private const KEY = 'resident_email';

    /** @param  list<int>  $accountIds */
    public function fromPush(string $tenantId, array $accountIds, PushMessage $message): int
    {
        try {
            if ($accountIds === [] || in_array($message->type, self::NOT_EMAILED, true)) {
                return 0;
            }

            $category = PushTypes::category($message->type);

            $accounts = ResidentAccount::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $accountIds)
                ->where('notify_email', true)
                ->where('channel', 'email')
                ->whereNotIn('status', [ResidentAccount::SUSPENDED, ResidentAccount::REVOKED, ResidentAccount::DELETED])
                ->get(['id', 'destination', 'notify_email_muted']);

            $estate = null;
            $sent = 0;

            foreach ($accounts as $account) {
                if ($category !== null && in_array($category, $account->notify_email_muted ?? [], true)) {
                    continue;
                }

                if (! filter_var($account->destination, FILTER_VALIDATE_EMAIL) || ! $this->firstInWindow($tenantId, (int) $account->id, $message)) {
                    continue;
                }

                $estate ??= (string) (Tenant::query()->whereKey($tenantId)->value('name') ?? 'your estate');

                Notification::route('mail', (string) $account->destination)
                    ->notify(CentralQueue::pin(new ResidentNewsEmailed($estate, $message->title, $message->body)));
                $sent++;
            }

            return $sent;
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);

            return 0;
        }
    }

    /** PushNotifier's window, under its own key: one statement decides, so two racing requests cannot both email. */
    private function firstInWindow(string $tenantId, int $accountId, PushMessage $message): bool
    {
        $window = (int) config('push.coalesce_seconds', 300);

        if ($window <= 0 || PushTypes::urgent($message->type)) {
            return true;
        }

        $now = Carbon::now();
        $key = ['tenant_id' => $tenantId, 'principal_type' => self::KEY, 'principal_id' => $accountId, 'type' => $message->type, 'record' => $message->record()];
        $table = static fn () => DB::connection('mysql')->table('push_coalescing');

        if ($table()->insertOrIgnore([...$key, 'last_pushed_at' => $now, 'suppressed' => 0]) === 1) {
            return true;
        }

        if ($table()->where($key)->where('last_pushed_at', '<=', $now->copy()->subSeconds($window))->update(['last_pushed_at' => $now, 'suppressed' => 0]) === 1) {
            return true;
        }

        $table()->where($key)->increment('suppressed');

        return false;
    }
}
