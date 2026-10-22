<?php

declare(strict_types=1);

namespace App\Services\ResidentApp;

use App\Support\JamaicanNumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * A text message handed to Twilio — the platform's first real SMS provider (M10 §1).
 *
 * WHY TWILIO. It originates to Jamaican mobiles (+1 876 / +1 658), it needs nothing
 * but an account, a sender and a card, and it is what the client named. The whole
 * integration is one authenticated form POST, so it is written against Laravel's own
 * HTTP client rather than Twilio's SDK: one fewer package on the panic path, and a
 * request `Http::fake()` can stand in for in a test.
 *
 *   POST https://api.twilio.com/2010-04-01/Accounts/{AccountSid}/Messages.json
 *   basic auth  AccountSid : AuthToken
 *   form        To, From, Body
 *
 * ONLY A 2xx CARRYING A MESSAGE SID IS A DELIVERY. That is the furthest this platform
 * can honestly claim — Twilio has the message; the carrier, the handset and the person
 * are all downstream, exactly as `SmsResult::SENT` says. A 2xx WITHOUT a sid is not
 * proof of anything and is reported as a failure, because "the provider said OK about
 * something" is not "the provider accepted this text".
 *
 * A REFUSAL IS AN ANSWER, NEVER AN EXCEPTION. Twilio says no with a status and its
 * own numeric code — 401/20003 for a wrong token, 400/21211 for a number it cannot
 * text — and both go into `SmsResult::failed()` as a CODE (`http_400:21211`), which
 * is what `alert_sms_dispatches.error` stores and what `SmsSettings` translates for
 * the operator. The message text of the refusal is not carried: it can quote the
 * number, and the ledger already has the number where it belongs. A connection that
 * never answers is `unreachable`, for the same reason — it is an ordinary outcome on
 * the panic path, not a fault for a caller to remember to catch.
 *
 * THE TOKEN NEVER LEAVES THIS CLASS. It travels in the Authorization header Guzzle
 * builds, never in the URL, so it is not in any exception message, any log line or
 * any result. The account SID is in the URL, because that is Twilio's design; it is
 * an identifier, not a secret.
 *
 * TIMEOUTS ARE SHORT BECAUSE THIS RUNS ON THE PANIC QUEUE. `SendPanicSms` texts each
 * site number in turn inside one job, so a provider that hangs costs every number
 * after it. Three seconds to connect and six in all, and NO RETRY — the job's own
 * `$tries = 1` explains why: a text has no idempotency key, Twilio may well have
 * accepted a message whose answer then timed out, and a retried emergency text is
 * four people told twice.
 */
final class TwilioSmsGateway implements SmsGateway
{
    public const DRIVER = 'twilio';

    private const ENDPOINT = 'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json';

    /** Seconds to establish the connection. */
    private const CONNECT_TIMEOUT = 3;

    /** Seconds for the whole exchange. */
    private const TIMEOUT = 6;

    /**
     * Twilio's own states for a message it has taken but will not deliver. A create
     * call should never answer with one, but if it did it would be a 2xx with a sid
     * describing a message that is not going anywhere.
     */
    private const DEAD_ON_ARRIVAL = ['failed', 'undelivered', 'canceled'];

    public function __construct(
        private readonly string $accountSid,
        private readonly string $token,
        private readonly string $from,
    ) {}

    public function send(string $to, string $message): SmsResult
    {
        if ($this->accountSid === '' || $this->token === '' || $this->from === '') {
            // Nothing was attempted: a request without credentials is a request for a 401.
            return SmsResult::failed(self::DRIVER, 'not_configured');
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->withBasicAuth($this->accountSid, $this->token)
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TIMEOUT)
                ->post(sprintf(self::ENDPOINT, rawurlencode($this->accountSid)), [
                    'To' => $to,
                    'From' => $this->sender(),
                    'Body' => $message,
                ]);
        } catch (ConnectionException) {
            return SmsResult::failed(self::DRIVER, 'unreachable');
        }

        $sid = $response->json('sid');

        if ($response->successful()
            && is_string($sid) && $sid !== ''
            && ! in_array((string) $response->json('status'), self::DEAD_ON_ARRIVAL, true)) {
            return SmsResult::sent(self::DRIVER, $sid);
        }

        return SmsResult::failed(self::DRIVER, $this->code($response));
    }

    /**
     * `http_<status>` and, when Twilio gave one, `:<its error code>`.
     *
     * A code, never prose — `SmsResult::failed()` says so, and the ledger's `error`
     * column is sixty-four characters for exactly this.
     */
    private function code(Response $response): string
    {
        $code = $response->json('code');

        return 'http_'.$response->status()
            .(is_int($code) || (is_string($code) && ctype_digit($code)) ? ':'.$code : '');
    }

    /**
     * The sender, in the form Twilio accepts.
     *
     * A NUMBER IS SENT IN E.164, AND WHAT THE DIRECTOR TYPED IS KEPT. Twilio refuses
     * "1-876-555-0147" as a sender; it accepts "+18765550147". `JamaicanNumber` is
     * the one place that reads a Jamaican number the way a Jamaican wrote it, so it
     * decides here too — and the setting keeps the characters that were typed, the
     * same rule `site_emergency_numbers` follows. An ALPHANUMERIC sender ID ("GEMINI")
     * is not a phone number at all and goes as written.
     */
    private function sender(): string
    {
        if (preg_match('/^[\d\s().+-]+$/', $this->from) !== 1) {
            return $this->from;
        }

        return JamaicanNumber::e164($this->from) ?? $this->from;
    }
}
