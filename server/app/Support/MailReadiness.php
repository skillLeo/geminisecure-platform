<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Whether this environment can actually send mail (13 B7).
 *
 * "`MAIL_MAILER=log` means no invitation, no password reset, no notice ever
 * leaves the machine." In development that is the point. In production it is a
 * platform that looks as though it is working: every invitation is "sent", no
 * one receives one, and nothing anywhere says so. So production refuses to boot
 * on a mailer that delivers nowhere, or on the placeholders `.env.example`
 * ships with — the failure is loud on the first request rather than silent for
 * a month.
 */
final class MailReadiness
{
    /** Mailers that deliver to nobody. */
    public const UNDELIVERABLE = ['log', 'array'];

    /**
     * Why this environment cannot send mail, or null when it can.
     *
     * `$acknowledged` is `MAIL_UNDELIVERABLE_ACKNOWLEDGED=true` (M7): a production server stood
     * up before its SMTP relay exists, by an operator who has said so in writing. The refusal
     * exists to stop a platform LOOKING as though it mails when it does not; a named, deliberate
     * acknowledgement in the environment file is the opposite of that, and `DEPLOYMENT.md` and
     * `QUESTIONS.md` carry the missing relay until it arrives. It excuses only `log` and
     * `array` — never an empty mailer, and never the `.env.example` placeholders.
     */
    public static function problem(string $environment, ?string $mailer, ?string $host, ?string $from, bool $acknowledged = false): ?string
    {
        if ($environment !== 'production') {
            return null;
        }

        if ($acknowledged && in_array($mailer, self::UNDELIVERABLE, true)) {
            return null;
        }

        if ($mailer === null || $mailer === '' || in_array($mailer, self::UNDELIVERABLE, true)) {
            return sprintf(
                'MAIL_MAILER is "%s" in production. No invitation, password reset or notice would leave the machine. Set a real mailer — see docs/DEPLOY.md.',
                (string) $mailer,
            );
        }

        foreach (['MAIL_HOST' => $host, 'MAIL_FROM_ADDRESS' => $from] as $key => $value) {
            if ($value !== null && str_contains($value, 'replace-with')) {
                return "{$key} is still the placeholder from .env.example. Mail would go nowhere, or from an address nobody owns.";
            }
        }

        return null;
    }
}
