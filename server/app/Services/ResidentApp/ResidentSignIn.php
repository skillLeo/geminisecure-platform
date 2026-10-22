<?php

declare(strict_types=1);

namespace App\Services\ResidentApp;

use App\Api\ApiError;
use App\Models\Estate\Resident;
use App\Models\ResidentAccount;
use App\Models\Tenant;
use App\Notifications\ResidentSignInCode;
use App\Services\Platform\SmsSettings;
use App\Services\Reviewer\ReviewerMode;
use App\Support\DemoMode;
use App\Support\DemonstrationAccounts;
use App\Support\EmailAddress;
use App\Support\Seeded\SeededRecipients;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Signing in to the Resident App with a one-time code (13 D3).
 *
 * NO PASSWORD. For Create account, and for an app that does not say, a code is sent
 * to whatever address or number is given, for the estate named, and the response is
 * the same whether an account exists or not. A SIGN-IN (`intent: sign_in`) is told
 * whether the register holds the address, on Hassam's word (M80 §2.1), within the
 * limits `requestCode` keeps. Proving the code proves control of the address — and
 * nothing more: the account that results is PENDING until the estate approves a
 * unit claim.
 *
 * THE CODE. Six digits, stored as an HMAC keyed by the application key and the
 * destination, good for ten minutes and five tries, once. A new request retires
 * the previous code; a request within a minute of the last is answered without
 * sending another.
 *
 * FIVE TRIES MEANS FIVE, HOWEVER THEY ARRIVE (B059). A guess first CLAIMS one of the
 * code's tries — one conditional UPDATE that succeeds only while fewer than five are
 * spent — and only a claimed try is compared. It used to read the row, check the
 * count it had read, compare, and only then add one: guesses landing in the same
 * instant all read "fewer than five" and were all compared, from as many addresses as
 * an attacker had, since the only brake was ten requests a minute per address.
 *
 * AND ONE ADDRESS, AT ONE ESTATE, GETS FIVE CODES AND TEN TRIES AN HOUR, whichever
 * addresses the requests come from. Five tries per code bounds one code; nothing
 * bounded the codes, and a fresh one with a fresh count could be minted every minute
 * for ever. The price is the resident's: somebody hammering their address keeps them
 * waiting for the hour too. It is the same trade the sign-in form makes, and the
 * alternative is a code that falls to patience. Both counters are kept for every
 * address asked about, account or none, so the limit says nothing about who exists.
 */
class ResidentSignIn
{
    public const TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    /** "Didn't get it? Resend in 30s" (M80 §2.1). */
    public const RESEND_SECONDS = 30;

    /** A sign-in (`intent: sign_in`), as opposed to Create account (`sign_up`) or an app that says neither. */
    public const SIGN_IN = 'sign_in';

    public const SIGN_UP = 'sign_up';

    /** Sign-in lookups of one address at one estate in an hour, found or not, so the count says nothing (M80 §2.1). */
    public const LOOKUPS_PER_HOUR = 10;

    /**
     * Sign-in lookups from one phone in an hour. The phone's install id is a CLAIM the phone makes, never an identity:
     * it only counts attempts, a new handset starts a count of its own, and it is never written beside an address.
     */
    public const DEVICE_LOOKUPS_PER_HOUR = 30;

    /** New codes minted for one estate + channel + address in an hour. */
    public const CODES_PER_HOUR = 5;

    /** Codes tried for one estate + channel + address in an hour, across every code. */
    public const VERIFIES_PER_HOUR = 10;

    private const HOUR_SECONDS = 3600;

    public function __construct(
        private readonly SmsGateway $sms,
        private readonly ReviewerMode $reviewer,
    ) {}

    /**
     * `$onWeb`: asked for on the web log-in page (M24 §4.4, ResidentDoorController), so the email says to type the code
     * there rather than in the app. Everything else about the code is the same code.
     *
     * @return array{expires_at: Carbon, resend_after: Carbon}
     */
    public function requestCode(Tenant $estate, string $channel, string $destination, bool $onWeb = false, ?string $intent = null, ?string $device = null): array
    {
        $destination = self::normalise($channel, $destination);

        self::refuseDemonstrationAddress($destination);

        /*
         * AN ADDRESS THAT CANNOT RECEIVE MAIL IS TOLD SO, BEFORE ANYTHING IS "SENT" (M18G §4.2). On 27 Sep a household
         * member typed "…@gmail.comm": the relay accepted both codes, the screen truthfully said so, and nothing could
         * ever arrive. A domain with nowhere to deliver to is refused here, with the address it probably meant — which
         * says only that a domain does not exist, never whether an account does.
         */
        if ($channel === 'email' && ! EmailAddress::receivesMail($destination)) {
            throw new ApiError(422, 'email_domain_unreachable', EmailAddress::cannotReceive($destination));
        }

        if ($channel === 'sms' && ! self::smsAvailable()) {
            throw new ApiError(503, 'sms_unavailable', 'Codes by text message are not available on this platform yet. Sign in with your email address.');
        }

        $hash = $this->destinationHash($destination);
        $now = Carbon::now();

        /*
         * A SIGN-IN LOOKS THE EMAIL UP FIRST (M80 §2.1, EST-B-03b). Any address used to reach the code screen, so a
         * resident whose estate holds an old address waited for a code nothing on file would ever be sent to. Signing in
         * now asks the register: an address no resident and no app account at this estate holds is told so, and no code
         * is made. Saying who is on the register is the trade Hassam accepted, with its limit: thirty lookups an hour
         * from one phone, five for an address the register does not hold, each logged by a hash, never the address.
         * Create account starts from an address nobody holds yet, and an app already on a phone says neither: both go on
         * as before.
         */
        if ($intent === self::SIGN_IN && $channel === 'email') {
            // Counting only: the phone's id is its own claim, so it can slow a phone down and never decide who signs in.
            $this->spend('lookups-device', $estate, $channel, $this->destinationHash('device|'.($device ?? 'unknown')), self::DEVICE_LOOKUPS_PER_HOUR,
                'Too many sign-in tries from this phone in the last hour. Try again in %s.');

            // Counted for the address whatever the answer, so neither the count nor its refusal says who is on the register.
            $this->spend('lookups', $estate, $channel, $hash, self::LOOKUPS_PER_HOUR,
                'Too many tries at this address in the last hour. Try again in %s.');

            $found = $this->onRegister($estate, $channel, $destination);

            // By the address's hash, never the address, and never beside the phone's id.
            Log::info('resident.sign_in.lookup', [
                'tenant' => (string) $estate->getTenantKey(),
                'found' => $found,
                'address' => mb_substr($hash, 0, 12),
            ]);

            // Not found says only that: nothing about the address comes back, masked or otherwise.
            if (! $found) {
                throw new ApiError(422, 'email_not_found', "We can't find that email. Check the spelling, or ask your estate office to add it.");
            }
        }

        /*
         * THE APP-STORE REVIEWER'S STATIC CODE (M5 §3). Their code never expires and is
         * already printed in the review notes, so nothing is issued and nothing is posted to
         * a mailbox nobody reads — and the answer is the SAME SHAPE any other address gets,
         * because this endpoint must never say who exists. A reviewer cannot run an artisan
         * command, which is exactly why a one-time code cannot be their way in.
         */
        $reviewerAccount = $this->reviewerAccount($estate, $channel, $destination);

        if ($reviewerAccount !== null) {
            $this->reviewer->record(ReviewerMode::STATIC_CODE, $reviewerAccount, ['for' => 'otp_request']);

            return ['expires_at' => $now->copy()->addMinutes(self::TTL_MINUTES), 'resend_after' => $now->copy()->addSeconds(self::RESEND_SECONDS)];
        }

        $latest = DB::connection('mysql')->table('resident_otps')
            ->where('tenant_id', $estate->getTenantKey())->where('channel', $channel)->where('destination_hash', $hash)
            ->whereNull('consumed_at')->orderByDesc('id')->first();

        if ($latest !== null && Carbon::parse((string) $latest->created_at)->addSeconds(self::RESEND_SECONDS)->isFuture()) {
            return [
                'expires_at' => Carbon::parse((string) $latest->expires_at),
                'resend_after' => Carbon::parse((string) $latest->created_at)->addSeconds(self::RESEND_SECONDS),
            ];
        }

        /*
         * A NEW CODE IS SPENT FROM THE HOUR'S FIVE, and refused before anything is minted
         * or sent — so the answer to a refused request is never "sent". The answer above,
         * which re-offers the code already sent, spends nothing.
         */
        $this->spend('codes', $estate, $channel, $hash, self::CODES_PER_HOUR,
            'Too many codes have been asked for at this address in the last hour. Try again in %s, or use the code already sent.');

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $expires = $now->copy()->addMinutes(self::TTL_MINUTES);

        DB::connection('mysql')->transaction(function () use ($estate, $channel, $hash, $code, $now, $expires): void {
            DB::connection('mysql')->table('resident_otps')
                ->where('tenant_id', $estate->getTenantKey())->where('channel', $channel)->where('destination_hash', $hash)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => $now, 'updated_at' => $now]);

            DB::connection('mysql')->table('resident_otps')->insert([
                'tenant_id' => $estate->getTenantKey(),
                'channel' => $channel,
                'destination_hash' => $hash,
                'code_hash' => $this->codeHash($code, $hash),
                'attempts' => 0,
                'expires_at' => $expires,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        if ($channel === 'email') {
            /*
             * AN EMAIL THAT DID NOT LEAVE IS NOT "SENT" EITHER (stage 2, R7). A mail
             * server that refused used to surface as a bare 500 while the code stayed
             * live, so the next request, inside the resend window, answered "sent" for
             * a code nobody received. It is retired exactly as a refused text is.
             */
            try {
                [, $dropped] = SeededRecipients::whileWatching(fn () => Notification::route('mail', $destination)
                    ->notifyNow(new ResidentSignInCode($code, (string) $estate->name, self::TTL_MINUTES, $onWeb)));
            } catch (\Throwable $e) {
                report($e);
                $this->retire($estate, $channel, $hash);

                throw new ApiError(503, 'mail_unavailable', 'The code could not be emailed just now — nothing was sent. Try again in a minute.');
            }

            /*
             * NOR ONE THE MAIL GUARD DROPPED (M79 §2, EST-B-03). An address only people a seeder invented hold gets no
             * mail (M35 §5, `SeededRecipients`), and the send returns as if it went: the request answered "sent" and the
             * app told the resident to watch an inbox nothing would reach. Withdrawn and refused, so the resend window
             * never re-offers it. It says only that this address takes no code, never who lives where: an address any
             * real person holds, account or none, is answered exactly as before.
             */
            if ($dropped !== []) {
                $this->retire($estate, $channel, $hash);

                throw new ApiError(422, 'email_not_sent', 'A sign-in code cannot be sent to this address. Ask your estate office to check the email on your household.');
            }
        } else {
            $result = $this->sms->send($destination, $code.' is your '.$estate->name.' Resident App code. It works once, for '.self::TTL_MINUTES.' minutes.');

            /*
             * "SENT" MEANS A PROVIDER TOOK IT (M10 §1). The answer to this request is
             * `sent: true`, and the app tells the resident to watch for a text — so a
             * provider that refused must not be answered with that. The code is
             * retired first: left live, the resend window would answer the next
             * request with "sent" for a code nobody received.
             */
            if (! $result->delivered()) {
                $this->retire($estate, $channel, $hash);

                throw new ApiError(503, 'sms_unavailable', 'The code could not be sent by text message. Sign in with your email address.');
            }
        }

        return ['expires_at' => $expires, 'resend_after' => $now->copy()->addSeconds(self::RESEND_SECONDS)];
    }

    /**
     * Whether this estate's register holds the address (M80 §2.1): an app account here, or a person on one of its
     * households who has not been taken off it. Read in the estate's own database, and only to answer yes or no.
     */
    private function onRegister(Tenant $estate, string $channel, string $destination): bool
    {
        $account = ResidentAccount::query()
            ->where('tenant_id', $estate->getTenantKey())
            ->where('channel', $channel)
            ->where('destination', $destination)
            ->whereNull('deleted_at')
            ->exists();

        if ($account || $channel !== 'email') {
            return $account;
        }

        return (bool) $estate->run(static fn (): bool => Resident::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$destination])
            ->whereNull('removed_at')
            ->exists());
    }

    /** Retire the live code for an address whose code never reached it. */
    private function retire(Tenant $estate, string $channel, string $hash): void
    {
        DB::connection('mysql')->table('resident_otps')
            ->where('tenant_id', $estate->getTenantKey())->where('channel', $channel)->where('destination_hash', $hash)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
    }

    /** Prove the code; the account it signs in to, created pending on first sign-in. */
    public function verify(Tenant $estate, string $channel, string $destination, string $code): ResidentAccount
    {
        $destination = self::normalise($channel, $destination);

        self::refuseDemonstrationAddress($destination);

        $hash = $this->destinationHash($destination);

        /*
         * The reviewer's STATIC code (M5 §3), checked before the one-time table so a code that
         * never expires needs no row in it. `ReviewerMode::codeMatches` refuses any account
         * without the flag, so an ordinary resident presenting this same six digits falls
         * through to the ordinary path below and is told their code is wrong.
         */
        $reviewerAccount = $this->reviewerAccount($estate, $channel, $destination);

        if ($reviewerAccount !== null && ReviewerMode::codeMatches($reviewerAccount, trim($code))) {
            $this->reviewer->record(ReviewerMode::STATIC_CODE, $reviewerAccount, ['for' => 'otp_verify']);
            $reviewerAccount->forceFill(['verified_at' => Carbon::now()])->save();

            return $reviewerAccount;
        }

        // Spent from the hour's ten before any code is looked at, right or wrong.
        $this->spend('tries', $estate, $channel, $hash, self::VERIFIES_PER_HOUR,
            'Too many codes have been tried at this address in the last hour. Try again in %s.');

        $otp = DB::connection('mysql')->table('resident_otps')
            ->where('tenant_id', $estate->getTenantKey())->where('channel', $channel)->where('destination_hash', $hash)
            ->whereNull('consumed_at')->orderByDesc('id')->first();

        if ($otp === null || Carbon::parse((string) $otp->expires_at)->isPast()) {
            throw self::expired();
        }

        /*
         * CLAIM A TRY, THEN COMPARE — never compare on a count that was read. What was read
         * above is already stale if other guesses are in flight; this UPDATE is the one
         * place the count is both checked and spent, and the database does both at once.
         */
        $now = Carbon::now();

        $claimed = DB::connection('mysql')->table('resident_otps')
            ->where('id', $otp->id)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', $now)
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->increment('attempts', 1, ['updated_at' => $now]);

        $current = DB::connection('mysql')->table('resident_otps')->where('id', $otp->id)->first(['attempts', 'consumed_at', 'expires_at']);

        if ($claimed === 0) {
            // No try left to claim: used or replaced meanwhile, run out of time, or spent.
            throw $current === null || $current->consumed_at !== null || Carbon::parse((string) $current->expires_at)->lessThanOrEqualTo($now)
                ? self::expired()
                : self::locked();
        }

        if (! hash_equals((string) $otp->code_hash, $this->codeHash(trim($code), $hash))) {
            // The tries left AFTER this one and every other that landed meanwhile.
            $left = self::MAX_ATTEMPTS - (int) ($current->attempts ?? self::MAX_ATTEMPTS);

            throw $left <= 0
                ? self::locked()
                : ApiError::unprocessable('otp_invalid', 'That code is not right. '.$left.' '.($left === 1 ? 'try' : 'tries').' left.');
        }

        /*
         * ONCE. Two right answers in flight both claim a try and both compare true; only the
         * one that spends the code signs in, and the other is told the code is used.
         */
        $spent = DB::connection('mysql')->table('resident_otps')
            ->where('id', $otp->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

        if ($spent === 0) {
            throw self::expired();
        }

        return $this->accountFor($estate, $channel, $destination);
    }

    /**
     * The account at an address the caller has JUST PROVEN — created pending when there is none. Only after a code:
     * `verify()` above, and a deleted account anonymised in the same sign-in (M18H), whose address now belongs to nobody.
     */
    public function accountFor(Tenant $estate, string $channel, string $destination): ResidentAccount
    {
        $account = ResidentAccount::query()->firstOrCreate(
            ['tenant_id' => $estate->getTenantKey(), 'channel' => $channel, 'destination' => self::normalise($channel, $destination)],
            ['status' => ResidentAccount::PENDING],
        );

        $account->forceFill(['verified_at' => Carbon::now()])->save();

        return $account;
    }

    /**
     * Whether text messages can actually be delivered here.
     *
     * THE ONE QUESTION, ASKED OF THE ONE PLACE (M10 §1): `SmsSettings::canSend()`,
     * which answers for the settings in force — the Director's saved provider, or
     * `.env` when nothing is saved — and refuses a Twilio row with no token or a
     * driver name with no gateway behind it.
     *
     * THE LOG DRIVER IS UNAVAILABLE EVERYWHERE, NOT ONLY IN PRODUCTION. This used to
     * let `log` through on every other server, so a resident asking for a code by
     * text was answered `sent: true` while the code went into a log file and reached
     * no phone. That is a claim that a text went out when none did, and the app
     * repeats it to the resident. Now the answer is `503 sms_unavailable` (which the
     * apps already turn into "sign in with email"), and it becomes available the day
     * a provider is connected, with no deploy. A developer reads a code with
     * `mobile:signin-code`, which signs in by email.
     */
    public static function smsAvailable(): bool
    {
        return app(SmsSettings::class)->canSend();
    }

    /**
     * Refuse a seeded demonstration address on a live system (M16 §1.2).
     *
     * A `.demo.geminisl.com` / `.test` / `quicklogin.local` destination is one a seeder
     * invented; with the demonstration switch off it cannot sign in, and it is refused
     * HERE rather than left to fail when the code cannot be delivered — the server says
     * no, it is not an undeliverable mailbox that happens to say no for us. The app-store
     * reviewer's own address (`reviewer@demo.geminisecure.app`) is NOT a demonstration
     * address, so this never touches the review path, which is gated by its own window.
     */
    private static function refuseDemonstrationAddress(string $destination): void
    {
        if (! DemoMode::enabled() && DemonstrationAccounts::isDemonstrationAddress($destination)) {
            throw new ApiError(422, 'demo_account_unavailable', 'Demonstration accounts are not available on this system.');
        }
    }

    public static function normalise(string $channel, string $destination): string
    {
        return $channel === 'email'
            ? mb_strtolower(trim($destination))
            : '+'.preg_replace('/\D+/', '', $destination);
    }

    /** "a•••@example.com", "+1876•••0147". */
    public static function mask(string $destination): string
    {
        if (str_contains($destination, '@')) {
            [$local, $domain] = explode('@', $destination, 2);

            return mb_substr($local, 0, 1).'•••@'.$domain;
        }

        return mb_substr($destination, 0, 5).'•••'.mb_substr($destination, -4);
    }

    /**
     * The FLAGGED reviewer account at this address on this estate, or null (M5 §3).
     *
     * Matched on the estate, the channel and the normalised destination — the same three
     * facts the one-time table is keyed by — so a reviewer account on one estate is nothing
     * to any other estate's sign-in, and the demonstration community stays a tenant like
     * every other.
     */
    private function reviewerAccount(Tenant $estate, string $channel, string $destination): ?ResidentAccount
    {
        $account = ResidentAccount::query()
            ->where('tenant_id', $estate->getTenantKey())
            ->where('channel', $channel)
            ->where('destination', $destination)
            ->where('reviewer', true)
            ->whereNull('deleted_at')
            ->first();

        return $account instanceof ResidentAccount && ReviewerMode::isReviewer($account) ? $account : null;
    }

    /**
     * Spend one of the hour's codes or tries for this estate + channel + address, or refuse.
     *
     * COUNTED FIRST, COMPARED SECOND. The counter is incremented and the new count read in
     * one step (an atomic increment on Redis and on the database store alike), and only
     * then compared — "check, then count" would be the very race this class was fixed for.
     * The hour runs from the first request counted, so however many are refused inside it,
     * waiting it out is enough.
     *
     * Keyed by the destination's HMAC, never the address itself: a cache key is not a
     * place for a resident's email address.
     *
     * @param  'codes'|'tries'|'lookups'|'lookups-device'  $what  the codes minted, the codes tried, a sign-in's lookups of an
     *                                                            address the register does not hold, and a phone's sign-in lookups
     * @param  string  $refusal  a sentence with one `%s` for how long to wait
     */
    private function spend(string $what, Tenant $estate, string $channel, string $hash, int $perHour, string $refusal): void
    {
        $key = 'resident-sign-in:'.$what.':'.$estate->getTenantKey().':'.$channel.':'.$hash;

        if (RateLimiter::hit($key, self::HOUR_SECONDS) <= $perHour) {
            return;
        }

        $wait = max(1, RateLimiter::availableIn($key));
        $minutes = (int) ceil($wait / 60);

        throw new ApiError(
            429,
            'rate_limited',
            sprintf($refusal, $minutes === 1 ? 'a minute' : $minutes.' minutes'),
            ['Retry-After' => (string) $wait],
        );
    }

    private static function expired(): ApiError
    {
        return ApiError::unprocessable('otp_expired', 'That code has expired or was replaced by a newer one. Ask for a new code.');
    }

    private static function locked(): ApiError
    {
        return new ApiError(423, 'otp_locked', 'Too many wrong codes. Ask for a new code.');
    }

    private function destinationHash(string $destination): string
    {
        return hash_hmac('sha256', $destination, (string) config('app.key'));
    }

    private function codeHash(string $code, string $destinationHash): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key').'|'.$destinationHash);
    }
}
