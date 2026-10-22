<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Api\DeviceContext;
use App\Models\DevicePushToken;
use App\Services\Audit\AuditLogger;
use App\Services\Ring\RingReadiness;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Whose phone an Expo push token is (M4 §3.2) — registered, moved, revoked.
 *
 * THE OWNER IS THE API TOKEN'S, NEVER THE REQUEST'S. `register()` takes the
 * `DeviceContext` the middleware resolved: the estate, the guard or the account.
 * The body says only what the phone alone can know — its Expo token, its platform,
 * the categories it has switched off.
 *
 * REGISTERING THE SAME TOKEN AGAIN MOVES IT. An Expo token names an INSTALL, and an
 * install changes hands: a handset passed to another guard, a resident who signs
 * out and a relative who signs in. The token is unique across the platform, so the
 * row is re-pointed at whoever registered it last — and the last guard's alerts
 * stop arriving on a phone that is no longer theirs.
 *
 * NOTHING IS DELETED. A revoked row keeps when and why; the address is simply never
 * used again unless the same install registers again.
 *
 * AND A MOVE IS NEVER SILENT. Re-pointing an address changes where a panic alert is
 * delivered, so a registration that takes a token from a DIFFERENT person, or from a
 * different estate, writes `device.push_address_moved` to the audit log naming both
 * sides. The same person re-registering their own phone (a rotated token, a switch
 * changed) is not a move and is not logged — it happens at every app start.
 *
 * THE TOKEN ITSELF NEVER REACHES A LOG. It is bound into the insert, and a failed
 * query's exception message carries its bindings, so the write is wrapped: the
 * database's own codes are logged and a bare exception is raised in its place.
 */
class PushTokens
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<string>  $muted  categories this phone switched off; unknown and locked names are dropped
     * @param  array<string, mixed>|null  $ring  the phone's call-style readiness, already parsed (`RingReadiness::parse`); null for none
     */
    public function register(DeviceContext $context, ?int $accessTokenId, string $expoToken, string $platform, array $muted, ?array $ring = null, mixed $quiet = null): DevicePushToken
    {
        [$principalType, $principalId] = $this->principal($context);

        $attributes = [
            // Quiet hours as of THIS registration (M50 §4.2 #5): one without them is an older app, or the switch off.
            ...DevicePushToken::quietFrom($quiet),

            /*
             * WHETHER IT CAN RING LIKE A CALL, as of THIS registration (the guard ring). A
             * registration without a report is an older app — or a resident's — and clears
             * it, so that phone is sent the ordinary alert every round: the safe direction.
             */
            'ring_version' => is_int($ring['v'] ?? null) ? $ring['v'] : null,
            'ring_readiness' => $ring,
            'ring_state' => RingReadiness::state($ring),
            'ring_checked_at' => $ring === null ? null : Carbon::now(),

            'tenant_id' => $context->tenantId,
            'principal_type' => $principalType,
            'principal_id' => $principalId,
            'app' => $context->app,
            'access_token_id' => $accessTokenId,
            'platform' => $platform,
            'muted' => array_values(array_intersect(PushTypes::mutable($context->app), $muted)),
            'last_registered_at' => Carbon::now(),
            'revoked_at' => null,
            'revoked_reason' => null,
        ];

        [$was, $row] = $this->write($expoToken, $attributes, $context, $accessTokenId, $principalType, $principalId);

        /*
         * Outside the transaction, and rescued: where a panic alert is delivered has
         * already changed, and a failure to write it down must not undo that. It is
         * logged as a fact about a DEVICE, beside `device.enrolled`.
         */
        if ($was !== null && ($was->tenant_id !== $context->tenantId || $was->principal_type !== $principalType || (int) $was->principal_id !== $principalId)) {
            // A test ring proved the LAST person's phone, not this one's.
            rescue(static fn () => $row->forceFill(['ring_proven_at' => null, 'ring_last_shown_at' => null])->save(), report: false);

            rescue(fn () => $this->audit->record(
                action: 'device.push_address_moved',
                entityType: 'DevicePushToken',
                entityId: (string) $row->id,
                before: ['tenant_id' => $was->tenant_id, 'principal_type' => $was->principal_type, 'principal_id' => (int) $was->principal_id, 'app' => $was->app],
                after: ['tenant_id' => $context->tenantId, 'principal_type' => $principalType, 'principal_id' => $principalId, 'app' => $context->app],
                tenantId: $context->tenantId,
            ));
        }

        return $row;
    }

    /**
     * The read and the write — and the only place the Expo token is bound into a query.
     *
     * A `QueryException`'s message carries its BINDINGS, so letting one out of here
     * would put a delivery address into the log and into every failed-request report.
     * It is caught, reduced to the database's own codes, and replaced. Every query that
     * names the token is therefore inside this one method, under this one catch.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: object|null, 1: DevicePushToken}  who held the address before, and the row now
     */
    private function write(string $expoToken, array $attributes, DeviceContext $context, ?int $accessTokenId, string $principalType, int $principalId): array
    {
        try {
            return DB::connection('mysql')->transaction(function () use ($context, $accessTokenId, $expoToken, $attributes, $principalType, $principalId): array {
                /*
                 * WHO HELD THIS ADDRESS A MOMENT AGO — read in the same transaction as the
                 * write, so nothing can take it in between and the audit entry names the
                 * person it really came from. Four columns and the row id, never the token.
                 */
                $was = DevicePushToken::query()->where('expo_token', $expoToken)->first(['id', 'tenant_id', 'principal_type', 'principal_id', 'app']);

                try {
                    $row = DevicePushToken::query()->updateOrCreate(['expo_token' => $expoToken], $attributes);
                } catch (UniqueConstraintViolationException) {
                    // Two registrations of one token at the same moment: the second finds the first's row.
                    $row = DevicePushToken::query()->updateOrCreate(['expo_token' => $expoToken], $attributes);
                }

                /*
                 * What this registration REPLACES: another address registered by the
                 * same sign-in (Expo rotated the token), and — a guard has ONE handset
                 * (`DeviceEnrolment`) — any other address of the same guard. A resident
                 * may be signed in on several phones, so theirs are left alone.
                 */
                DevicePushToken::query()->live()
                    ->whereKeyNot($row->id)
                    ->where(static function ($q) use ($context, $accessTokenId, $principalType, $principalId): void {
                        $q->whereRaw('0 = 1');

                        if ($accessTokenId !== null) {
                            $q->orWhere('access_token_id', $accessTokenId);
                        }

                        if ($principalType === DevicePushToken::GUARD) {
                            $q->orWhere(static fn ($g) => $g->where('tenant_id', $context->tenantId)->where('principal_type', $principalType)->where('principal_id', $principalId));
                        }
                    })
                    ->update(['revoked_at' => Carbon::now(), 'revoked_reason' => DevicePushToken::REPLACED]);

                return [$was, $row];
            });
        } catch (QueryException $failure) {
            // The SQLSTATE and the driver's own number say what went wrong; neither can contain a token.
            Log::warning('push.register_failed', [
                'sqlstate' => (string) $failure->getCode(),
                'driver_code' => isset($failure->errorInfo[1]) ? (int) $failure->errorInfo[1] : null,
                'principal_type' => $principalType,
            ]);

            throw new RuntimeException('A push address could not be recorded.');
        }
    }

    /**
     * Sign-out: this SIGN-IN's addresses stop being used. Called before the phone
     * drops its API token. With no token id to go by (a test's transient token),
     * every address of the principal goes.
     */
    public function unregister(DeviceContext $context, ?int $accessTokenId): int
    {
        [$principalType, $principalId] = $this->principal($context);

        return DevicePushToken::query()->live()
            ->ownedBy($context->tenantId, $principalType, $principalId)
            ->when($accessTokenId !== null, static fn ($q) => $q->where('access_token_id', $accessTokenId))
            ->update(['revoked_at' => Carbon::now(), 'revoked_reason' => DevicePushToken::SIGNED_OUT]);
    }

    /**
     * Access ended on the server's side — a handset revoked or re-bound, an account
     * suspended, a household member removed. Every address of the principal goes,
     * now, rather than when the next push would have found out.
     */
    public function revokeFor(string $tenantId, string $principalType, int $principalId, string $reason = DevicePushToken::ACCESS_REVOKED): int
    {
        return DevicePushToken::query()->live()
            ->ownedBy($tenantId, $principalType, $principalId)
            ->update(['revoked_at' => Carbon::now(), 'revoked_reason' => $reason]);
    }

    /** @return array{0: string, 1: int} */
    private function principal(DeviceContext $context): array
    {
        return $context->guard !== null
            ? [DevicePushToken::GUARD, (int) $context->guard->id]
            : [DevicePushToken::RESIDENT, (int) $context->residentOrFail()->id];
    }
}
