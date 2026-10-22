<?php

declare(strict_types=1);

namespace App\Services\ResidentApp;

use App\Support\JamaicanNumber;
use Illuminate\Support\Facades\Log;

/**
 * A text message written down and sent to NOBODY — the platform's default, because
 * no SMS provider has been bought yet.
 *
 * IT IS NOT A STUB AND IT IS NOT A PRETENCE. It does the one honest thing available
 * to a platform with no provider: it records exactly what would have gone out, and
 * answers `SmsResult::recorded()` — which `delivered()` reports as false, which the
 * panic ledger writes down as `recorded`, and which the resident's panic screen never
 * counts as a phone that was reached. Everything downstream of this class is complete
 * and proven; the only missing piece is an account.
 *
 * THE NUMBER IS MASKED IN THE LOG AND THE SIGN-IN CODE IS NOT. That asymmetry is
 * deliberate and it is why production never reaches this class for sign-in codes
 * (`ResidentSignIn::smsAvailable()`): a one-time code in a log file is a credential.
 * A PANIC message carries no credential — it says where and what, and nothing about
 * money, a ballot or who pressed the button — so writing it down in full on a
 * developer's machine tells nobody anything they could misuse, and being able to read
 * the exact words that would have been texted is the whole point of this driver.
 *
 * The full number lives in `alert_sms_dispatches`, where it belongs: a ledger row is
 * the record of who was told about an emergency, and a log file is not.
 */
final class LogSmsGateway implements SmsGateway
{
    public const DRIVER = 'log';

    public function send(string $to, string $message): SmsResult
    {
        Log::info('SMS (log driver — written down, delivered to nobody) to '.JamaicanNumber::mask($to).': '.$message);

        return SmsResult::recorded(self::DRIVER);
    }
}
