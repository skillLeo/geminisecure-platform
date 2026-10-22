<?php

declare(strict_types=1);

namespace App\Services\ResidentApp;

/**
 * THE PLATFORM'S ONE TEXT-MESSAGE DRIVER (13 D3, Q-M09).
 *
 * It lives in this namespace because the Resident App's sign-in code was the first
 * thing that needed a text; it is not the Resident App's any more. A panic now raises
 * a text to the site's emergency numbers through this same interface
 * (`Jobs\Alerts\SendPanicSms`), deliberately, so that the day a provider is bought
 * there is ONE class to write and one setting to change — not two implementations
 * free to disagree about whether a message went.
 *
 * TWO IMPLEMENTATIONS (M10 §1). `TwilioSmsGateway` hands the message to Twilio and is
 * chosen by the Director under Platform settings → SMS, with no deploy. Until somebody
 * does, the platform runs on `LogSmsGateway`, which writes the message down and
 * delivers it to nobody, and says so in its answer (`SmsResult::RECORDED`).
 * `ResidentSignIn::smsAvailable()` refuses the log driver in production for sign-in
 * codes; the panic path does not refuse it, because writing down what would have been
 * texted is worth more than silence and costs nothing — but it never reports it as a
 * message that was sent.
 *
 * WHAT ADDING A THIRD PROVIDER LOOKS LIKE, in full:
 *   1. a class in this namespace implementing this interface, returning
 *      `SmsResult::sent()` with the provider's own message id;
 *   2. its code and label in `PlatformSmsSetting::DRIVERS`, which is what the
 *      settings screen offers and what `save()` and `canSend()` accept;
 *   3. one match arm in `App\Providers\AppServiceProvider::register()`, reading the
 *      `services.sms.*` values the settings screen fills in.
 * Nothing else in the application changes, and no caller is touched.
 *
 * IT RETURNS A RESULT AND NEVER THROWS FOR AN ORDINARY REFUSAL. A provider that says
 * no is `SmsResult::failed()`, because a thrown exception on the path of an emergency
 * is something a caller has to remember to catch. A genuine fault may still throw;
 * every caller on the panic path is wrapped.
 */
interface SmsGateway
{
    public function send(string $to, string $message): SmsResult;
}
