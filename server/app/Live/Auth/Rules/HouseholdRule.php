<?php

declare(strict_types=1);

namespace App\Live\Auth\Rules;

use App\Api\AccessLevels;
use App\Api\ApiError;
use App\Api\AppMatrix;
use App\Api\DeviceContext;
use App\Live\Auth\EstateScope;
use App\Live\Auth\Principal;
use App\Live\ChannelName;
use App\Models\ResidentAccount;
use App\Services\ResidentApp\ResidentAccounts;
use Illuminate\Support\Carbon;

/**
 * `household.{t}.{h}` — one household's passes, dues, tickets and gate answers
 * (DESIGN §3.1).
 *
 * An ACTIVE resident account of t (never pending, revoked or suspended) with
 * `household:read`, whose own home — `ResidentAccounts::home()`, the call every
 * Resident API household read goes through — is household h. So the channel is exactly
 * as wide as the API: whatever narrows `home()` narrows this, and a household id from
 * another estate never matches, because the estate half runs in the account's own
 * estate database.
 *
 * AND ONLY AT A LEVEL THAT READS EVERYTHING IT CARRIES. The API checks a person's access
 * level (Member, Dependent, Helper) on every request (`EnforceAccessLevel`), and a
 * channel is one audience: it cannot send passes to one member and not another. So the
 * household channel admits a level only if that level reaches every read its events are
 * about (`CARRIES`); a Dependent or a Helper, whom the API refuses passes, dues or the
 * gate, is refused the channel, and their screens stay honest by catching up instead. The
 * level is read from the register entry the account signs in as, fresh, as the API reads
 * it, and a missing entry is the narrowest level (`AccessLevels::of`).
 */
final class HouseholdRule implements Rule
{
    /**
     * The Resident API reads whose records this channel's events describe (DESIGN §2.8–§2.12,
     * §4.3): the household itself, its visitor passes, its dues and payments, the gate's
     * questions to it, and its tickets.
     *
     * @var list<string>
     */
    public const CARRIES = ['me.household', 'visitor_passes.index', 'invoices.index', 'payments.index', 'approvals.respond', 'tickets.index'];

    /**
     * Whether a level reaches every one of these endpoints (`AppMatrix::LEVELS`).
     *
     * @param  list<string>  $endpoints
     */
    public static function levelReaches(string $level, array $endpoints): bool
    {
        foreach ($endpoints as $endpoint) {
            if (! AppMatrix::levelAllows($level, $endpoint)) {
                return false;
            }
        }

        return true;
    }

    public function central(Principal $principal, ChannelName $channel): bool
    {
        return $channel->tenant !== null
            && $principal->resident($channel->tenant, allowPending: false) !== null
            && $principal->tokenCan(AppMatrix::ability('household', AppMatrix::READ));
    }

    public function needsEstate(): bool
    {
        return true;
    }

    public function estate(Principal $principal, ChannelName $channel): bool
    {
        $home = $channel->tenant === null ? null : self::home($principal, $channel->tenant);

        return $home !== null
            && $channel->id !== null
            && (string) $home['household'] === $channel->id
            && self::levelReaches($home['level'], self::CARRIES);
    }

    /**
     * The account's household id, its unit's block and its access level, read in its own
     * estate — or null when it has none (`home()` refuses a pending or revoked account).
     *
     * @return array{household: int, block: string|null, level: string}|null
     */
    public static function home(Principal $principal, string $tenantId): ?array
    {
        $account = $principal->resident($tenantId, allowPending: false);

        if (! $account instanceof ResidentAccount) {
            return null;
        }

        return EstateScope::run($tenantId, static function () use ($account, $tenantId): ?array {
            try {
                $home = app(ResidentAccounts::class)->home(
                    new DeviceContext(AppMatrix::RESIDENT, $tenantId, null, $account, Carbon::now()),
                );
            } catch (ApiError) {
                return null;
            }

            $block = $home['unit']->block;

            return [
                'household' => (int) $home['household']->id,
                'block' => is_string($block) && trim($block) !== '' ? $block : null,
                'level' => AccessLevels::of($home['resident']),
            ];
        });
    }
}
