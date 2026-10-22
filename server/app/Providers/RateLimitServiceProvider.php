<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use LogicException;

/**
 * What each door will accept, and how fast.
 *
 * /api/v1 had no limit of any kind. Every endpoint is behind a token and an
 * ability, which stops a stranger — and stops nothing at all once a handset is
 * lost, cloned or left in a taxi. A credential that can raise an alert can
 * raise ten thousand, and the queue a dispatcher watches is the thing that
 * fills up.
 *
 * KEYED ON THE TOKEN, NOT THE IP. Every guard at one estate can sit behind one
 * mobile carrier NAT, so an IP limit would throttle a whole gate because one
 * handset misbehaved. The token is the device, which is the thing that can be
 * revoked and the thing whose behaviour is actually being described.
 *
 * THE PANIC PATH IS THE ONE THAT NEEDS THE MOST CARE, AND IT GETS THE MOST
 * ROOM. A limit that silences an alert is worse than the flood it prevents, so
 * the alert ceiling is set where no frightened person could reach it and only a
 * loop can: a human jabbing a panic button cannot beat two per second for a
 * minute, and thirty alerts from one device already means the queue knows.
 * Retries are cheaper still — they carry the same idempotency key and return
 * the row that already exists rather than making a second one.
 */
class RateLimitServiceProvider extends ServiceProvider
{
    /**
     * Alerts: generous, because this is the life-safety path.
     *
     * High enough that no person in trouble can hit it, low enough that a
     * runaway handset cannot bury a dispatcher's queue.
     */
    private const ALERTS_PER_MINUTE = 30;

    /**
     * Gate events: a busy main gate at shift change, doubled.
     *
     * An admission takes a guard several seconds of talking to somebody, so two
     * a second sustained is not a gate — it is a script.
     */
    private const GATE_EVENTS_PER_MINUTE = 120;

    /**
     * Clocking on and off: deliberately tight.
     *
     * A handset clocks on once a shift. Even allowing for a bad signal and a
     * queue of retries, twenty in a minute is already far more than the act can
     * honestly need.
     */
    private const SHIFT_CLOCK_PER_MINUTE = 20;

    /**
     * Sign-in attempts, per address and per account.
     *
     * Both, because they stop different attacks: per-IP stops one machine
     * working through a password list, and per-account stops a distributed
     * attempt at one known address. Five a minute leaves room for a person
     * mistyping and none for a program.
     *
     * The price of the per-account limit is paid by the account's owner: somebody
     * guessing at a known address also keeps its owner out for the minute they
     * keep guessing. That is the trade the docblocks above always described, and
     * the only alternative is letting the guessing go on.
     */
    private const LOGIN_PER_MINUTE = 5;

    /**
     * Reads from a handset: a sync that pulls every screen at once, twice.
     */
    private const READS_PER_MINUTE = 240;

    /**
     * Ordinary writes from a handset that are not the three above — a ticket,
     * a leave request, a checkpoint scan. A patrol of nine checkpoints and a
     * sync of a morning's queue fit with room to spare.
     */
    private const WRITES_PER_MINUTE = 120;

    /**
     * Enrolment and one-time codes, per address: there is no token yet to key
     * on, and a code is a secret worth guessing.
     */
    private const ENROL_PER_MINUTE = 10;

    /**
     * A supervisor's PIN typed into a guard's handset (M4 §3.4): six digits, so the
     * thing being limited is guessing. A supervisor mistyping needs two or three
     * tries; five a minute and twenty an hour from one handset is room for that and
     * none for a loop. The PIN itself also locks after five wrong tries, whichever
     * handsets they came from — this is the second lock, not the only one.
     */
    private const OVERRIDE_PER_MINUTE = 5;

    private const OVERRIDE_PER_HOUR = 20;

    /**
     * A rung phone's answers and receipts (the guard ring), which carry a ring ticket and no
     * bearer token — so TWO limits (G28). Per ticket, so one phone's retries cannot starve
     * another's; and per address, always, because the caller chooses the ticket and a limit
     * keyed on it alone would give every forged ticket a fresh bucket. The address figure is
     * high on purpose: mobile carriers put many phones behind one address, and one round of
     * fourteen phones already sends about thirty receipts. An answer refused here is kept on
     * the phone and sent again in ten seconds, so a busy address delays it and never loses it.
     */
    private const RING_PER_TICKET_PER_MINUTE = 30;

    private const RING_PER_ADDRESS_PER_MINUTE = 120;

    /** A test ring, from the Call-style screen: one a minute per handset. */
    private const RING_TEST_PER_MINUTE = 1;

    /**
     * The card provider's webhooks, per sending address (M16 §2.2). High on purpose: dues day is everyone paying in
     * the same two days, each payment is several webhooks, and a provider retries anything it is not answered. The
     * signature is what keeps strangers out; this only stops a flood.
     */
    private const PAYMENT_WEBHOOKS_PER_MINUTE = 1200;

    /**
     * "Download my data" (M50 §4.3): a person's whole record, read from every table that names them and laid out as a
     * PDF — the heaviest read a phone can ask for. A bad signal may cost a try or two; nobody needs more than three in a
     * minute or a dozen in an hour.
     */
    private const DATA_EXPORT_PER_MINUTE = 3;

    private const DATA_EXPORT_PER_HOUR = 12;

    public function boot(): void
    {
        RateLimiter::for('api-ring', static function (Request $request): array {
            $ticket = $request->input('rt');

            return [
                Limit::perMinute(self::RING_PER_TICKET_PER_MINUTE)->by('ring-ticket:'.hash('sha256', is_string($ticket) ? $ticket : '')),
                Limit::perMinute(self::RING_PER_ADDRESS_PER_MINUTE)->by('ring-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('api-ring-test', fn (Request $request) => Limit::perMinute(self::RING_TEST_PER_MINUTE)
            ->by('ring-test:'.$this->device($request)));

        RateLimiter::for('payment-webhooks', static fn (Request $request) => Limit::perMinute(self::PAYMENT_WEBHOOKS_PER_MINUTE)
            ->by('payment-webhook:'.$request->ip()));

        RateLimiter::for('api-supervisor-override', fn (Request $request) => [
            Limit::perMinute(self::OVERRIDE_PER_MINUTE)->by('override-minute:'.$this->device($request)),
            Limit::perHour(self::OVERRIDE_PER_HOUR)->by('override-hour:'.$this->device($request)),
        ]);

        RateLimiter::for('api-alerts', fn (Request $request) => Limit::perMinute(self::ALERTS_PER_MINUTE)
            ->by($this->device($request)));

        RateLimiter::for('api-gate-events', fn (Request $request) => Limit::perMinute(self::GATE_EVENTS_PER_MINUTE)
            ->by($this->device($request)));

        RateLimiter::for('api-shift-clock', fn (Request $request) => Limit::perMinute(self::SHIFT_CLOCK_PER_MINUTE)
            ->by($this->device($request)));

        RateLimiter::for('api-reads', fn (Request $request) => Limit::perMinute(self::READS_PER_MINUTE)
            ->by($this->device($request)));

        RateLimiter::for('api-data-export', fn (Request $request) => [
            Limit::perMinute(self::DATA_EXPORT_PER_MINUTE)->by('data-export-minute:'.$this->device($request)),
            Limit::perHour(self::DATA_EXPORT_PER_HOUR)->by('data-export-hour:'.$this->device($request)),
        ]);

        RateLimiter::for('api-writes', fn (Request $request) => Limit::perMinute(self::WRITES_PER_MINUTE)
            ->by($this->device($request)));

        RateLimiter::for('api-enrol', fn (Request $request) => Limit::perMinute(self::ENROL_PER_MINUTE)
            ->by('enrol:'.$request->ip()));

        /*
         * PER ADDRESS, AND PER ACCOUNT ALONE (B070). The second key was `email|ip`,
         * which contains the address and so, on any one address, is never stricter
         * than the first: nothing limited the account, and N source addresses gave
         * 5N guesses a minute at one Director's known address. It is the account now,
         * whatever address the guess comes from.
         */
        RateLimiter::for('login', function (Request $request): array {
            $limits = [Limit::perMinute(self::LOGIN_PER_MINUTE)->by('ip:'.$request->ip())];

            $account = $this->signInAccount($request);

            if ($account !== null) {
                $limits[] = Limit::perMinute(self::LOGIN_PER_MINUTE)->by($account);
            }

            return $limits;
        });
    }

    /**
     * The account a sign-in-shaped request is guessing at, for limiting purposes.
     *
     * WHO IS SIGNED IN, where the form asks a signed-in person for their password
     * again (`account/override-pin`): the password being guessed there is theirs.
     * Asked first, so a field the request adds cannot move it to a fresh bucket.
     *
     * OTHERWISE THE TYPED ADDRESS, AS THE DATABASE WILL MATCH IT, on every door that
     * asks for one — sign-in, forgot password and reset password on both consoles —
     * so they all spend one account's five a minute, whichever door and whichever
     * address a guess arrives by. See `accountNamed()` for why that is not simply the
     * address lower-cased.
     *
     * NULL WHEN THE REQUEST NAMES NO ACCOUNT — accepting an invitation, whose token
     * is the credential. Such a request is limited per address only. A key built from
     * an absent address would put every invitation on the platform into one shared
     * five-a-minute bucket, and one person could stop everybody else accepting theirs.
     */
    private function signInAccount(Request $request): ?string
    {
        $user = $request->user();

        if ($user !== null) {
            return 'user:'.$user->getAuthIdentifier();
        }

        $email = $request->input('email');

        return is_string($email) && trim($email) !== '' ? $this->accountNamed(trim($email)) : null;
    }

    /**
     * One key for every spelling of an address that finds the same account.
     *
     * NOT `mb_strtolower()`. `users.email` is compared under the connection's collation
     * (`utf8mb4_unicode_ci`), which ignores accents and width as well as case:
     * `jáne@x.com`, `ＪＡＮＥ@x.com` and `jane@x.com` all sign in to one account, and
     * the `email` rule accepts every one of them. Lower-casing would give each spelling
     * a bucket of its own, and a four-letter name has hundreds — the distributed
     * guessing this limit exists to stop, one spelling per address.
     *
     * So the key is the address's weight under that same collation: MySQL's own
     * answer to "which strings are equal to this one", whether or not an account
     * holds it. Asked of the database rather than a second table of equivalences kept
     * here, because only the database's answer is the one `Auth::attempt()` gets —
     * and without looking the account up, so an address that holds no account is
     * limited exactly like one that does and the limit says nothing about which is
     * which. The collation pads with spaces, so trailing space weights are dropped as
     * its comparison drops them.
     */
    private function accountNamed(string $email): string
    {
        $connection = (new User)->getConnection();
        $collation = (string) $connection->getConfig('collation');

        if (preg_match('/^[A-Za-z0-9_]+$/', $collation) !== 1) {
            throw new LogicException('The central connection names no usable collation to limit sign-in by.');
        }

        $row = $connection->selectOne(
            "select hex(weight_string(convert(? using utf8mb4) collate {$collation})) as weight, "
            ."hex(weight_string(convert(' ' using utf8mb4) collate {$collation})) as space",
            [$email],
        );

        $weight = is_object($row) && is_string($row->weight ?? null) ? $row->weight : '';
        $space = is_object($row) && is_string($row->space ?? null) ? $row->space : '';

        while ($space !== '' && str_ends_with($weight, $space)) {
            $weight = substr($weight, 0, -strlen($space));
        }

        return 'account:'.hash('sha256', $weight);
    }

    /**
     * Which device is speaking, for limiting purposes.
     *
     * READ FROM THE BEARER HEADER, NOT FROM `$request->user()`, and that is not
     * a shortcut — it is the only thing that works. Laravel's middleware
     * priority puts `ThrottleRequests` BEFORE `Authenticate`, so a limiter that
     * asked for the authenticated user would find null on every request and
     * quietly fall back to the address. The limit would still appear to work,
     * and every handset at one estate would share one bucket: a single runaway
     * phone taking the panic button away from every guard behind the same
     * mobile carrier NAT. A test caught it; nothing else would have.
     *
     * HASHED, so a credential does not become a cache key. The raw token is
     * what a device presents; what is stored here only has to be unique per
     * device, and sha256 of it is.
     *
     * Falls back to the address when there is no token at all. Those requests
     * are refused a moment later by `auth:sanctum`, and a caller with no
     * credential must not be handed an unlimited bucket on the way to being
     * rejected.
     */
    private function device(Request $request): string
    {
        $bearer = $request->bearerToken();

        return $bearer === null || $bearer === ''
            ? 'ip:'.$request->ip()
            : 'token:'.hash('sha256', $bearer);
    }
}
