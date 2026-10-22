<?php

declare(strict_types=1);

namespace App\Jobs\Alerts;

use App\Models\AlertSmsDispatch;
use App\Models\DuressAlert;
use App\Services\Dispatch\HeadsOfSecuritySms;
use App\Services\Dispatch\PanicSms;
use App\Services\Dispatch\SiteEscalation;
use App\Services\Platform\OperatorEstates;
use App\Services\Platform\SmsSettings;
use App\Services\ResidentApp\LogSmsGateway;
use App\Services\ResidentApp\SmsGateway;
use App\Services\ResidentApp\SmsResult;
use App\Support\JamaicanNumber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * THE ESTATE'S HEAD OF SECURITY, TEXTED BY THE RING'S ESCALATION (the guard ring §3.4, G14).
 *
 * "If nobody is on duty or rostered, or nobody has acknowledged within 60 s, ring every
 * active guard assigned to that estate, and the estate's own Head of Security." A Head of
 * Security is a console user with no push address, so ringing them means a text, and a
 * mail beside it (`PanicStillUnanswered`).
 *
 * WHO. The estate's own Head of Security, read directly (`SiteEscalation::
 * estateHeadsOfSecurity()`), and NOT through `HeadsOfSecuritySms::plan()`: that plan drops
 * a person already on the Gemini list and never texts one whose phone is one of the
 * site's numbers — and the estate's Head of Security usually IS one of the site's four
 * numbers, which this does not text again. Through the plan, the escalation would have
 * texted nobody. Numbers are de-duplicated within this job only.
 *
 * WHAT. `PanicSms::unansweredBody()`: a follow-up, and says so — nobody answered, or nobody
 * was on duty, and how many phones are now being rung. DRILL first on a drill.
 *
 * WRITTEN DOWN AS THE RAISE'S TEXTS ARE: one `alert_sms_dispatches` row per Head of Security
 * with a phone, after the attempt, number masked — under its OWN audience, `escalation`,
 * so the raise's staff totals on the alert are never moved by it. It never calls
 * `HeadsOfSecuritySms::raise()`, which rewrites them.
 *
 * NOT FROM THE DEMONSTRATION ESTATE, NOT FOR A SIMULATED ALERT — asked again here, as the
 * raise's own text job asks twice. NO RETRY: an emergency text is never re-sent.
 *
 * ITS CONSTRUCTOR NEVER GROWS A PROMOTED PARAMETER (G2).
 */
class SendRingEscalationSms implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    /** What the row's `role` and `label` say: the office, never the person. */
    public const ROLE = 'estate_head_of_security';

    public const LABEL = 'Estate Head of Security';

    public function __construct(
        public readonly int $alertId,
        public readonly string $reason,
        public readonly int $phones,
    ) {}

    public function handle(SiteEscalation $escalation, SmsGateway $sms, SmsSettings $settings): void
    {
        $alert = DuressAlert::query()->with('estate')->find($this->alertId);

        if ($alert === null || $alert->is_simulated || OperatorEstates::isDemonstration((string) $alert->tenant_id)) {
            return;
        }

        $gateway = $settings->canSend() ? $sms : new LogSmsGateway;
        $driver = $gateway instanceof LogSmsGateway ? LogSmsGateway::DRIVER : (string) (config('services.sms.driver') ?: LogSmsGateway::DRIVER);
        $body = PanicSms::unansweredBody($alert, $this->reason, $this->phones);

        foreach (self::recipients($escalation, (string) $alert->tenant_id) as $head) {
            if ($head['duplicate']) {
                $this->write($alert, $head, AlertSmsDispatch::DEDUPLICATED, $driver, null, HeadsOfSecuritySms::SAME_AS_ANOTHER_HEAD);

                continue;
            }

            $result = $head['e164'] === null ? SmsResult::unroutable($driver) : $this->sendOne($gateway, $driver, $head['e164'], $body);

            $this->write($alert, $head, $result->status, $result->driver, $result->reference, $result->error);
        }
    }

    /**
     * The estate's Heads of Security with a phone on file, in the order they are texted,
     * each number once within this job.
     *
     * @return list<array{user_id: int, phone: string, e164: string|null, duplicate: bool}>
     */
    public static function recipients(SiteEscalation $escalation, string $tenantId): array
    {
        $out = [];
        $seen = [];

        foreach ($escalation->estateHeadsOfSecurity($tenantId, limit: null) as $user) {
            $phone = trim((string) $user->getAttribute('contact_phone'));

            if ($phone === '') {
                continue;
            }

            $e164 = JamaicanNumber::e164($phone);
            $duplicate = $e164 !== null && isset($seen[$e164]);

            if ($e164 !== null) {
                $seen[$e164] = true;
            }

            $out[] = ['user_id' => (int) $user->getKey(), 'phone' => $phone, 'e164' => $e164, 'duplicate' => $duplicate];
        }

        return $out;
    }

    private function sendOne(SmsGateway $sms, string $driver, string $e164, string $body): SmsResult
    {
        try {
            return $sms->send($e164, $body);
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);

            return SmsResult::failed($driver, class_basename($e));
        }
    }

    /** @param  array{user_id: int, phone: string, e164: string|null, duplicate: bool}  $head */
    private function write(DuressAlert $alert, array $head, string $status, string $driver, ?string $reference, ?string $error): void
    {
        rescue(static fn () => AlertSmsDispatch::query()->create([
            'duress_alert_id' => (int) $alert->id,
            'tenant_id' => (string) $alert->tenant_id,
            'audience' => AlertSmsDispatch::ESCALATION,
            'site_emergency_number_id' => null,
            'household_emergency_contact_id' => null,
            'user_id' => $head['user_id'],
            'role' => self::ROLE,
            'label' => self::LABEL,

            // MASKED, and no diallable form — the staff rows' rule (`AlertSmsDispatch`).
            'number' => JamaicanNumber::mask($head['e164'] ?? $head['phone']),
            'e164' => null,

            'status' => $status,
            'driver' => $driver,
            'reference' => $reference,
            'error' => $error === null ? null : substr($error, 0, 64),
            'attempted_at' => Carbon::now(),
        ]), report: false);
    }
}
