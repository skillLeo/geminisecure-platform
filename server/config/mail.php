<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    // smtp, not log, when nothing is set: an unset mailer should fail to send
    // loudly rather than write every invitation to a file nobody reads (13 B7).
    'default' => env('MAIL_MAILER', 'smtp'),

    // Production may run on `log` or `array` ONLY with this set, in writing, by the
    // operator who stood the server up before its SMTP relay existed (M7). Nothing else
    // excuses an undeliverable mailer in production; see `App\Support\MailReadiness`.
    'undeliverable_acknowledged' => (bool) env('MAIL_UNDELIVERABLE_ACKNOWLEDGED', false),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            // EIGHT SECONDS, not for ever (DESIGN INV-5). An invitation and a sign-in code are sent while the
            // person waits, and the phone gives up at fifteen; a relay that never answers must be a refusal
            // they are told about, not a request that hangs until the phone says "no connection".
            'timeout' => (float) env('MAIL_TIMEOUT', 8),
            // M12 §5.1: wait 1 s, then 3 s, between the three attempts (ResilientSmtpTransport). Off only in the
            // test run (phpunit.xml), where dozens of sends go to a relay that is deliberately not there.
            'retry_backoff' => (bool) env('MAIL_RETRY_BACKOFF', true),
            // M18G: before a code or an invite is sent, the domain is asked whether it can receive mail at all (an MX
            // record, or an address) — "…@gmail.comm" has neither. Read as `mail.mailers.smtp.check_domains`; off in the
            // test run (phpunit.xml), whose addresses are `example.com` and `.test` and which has no DNS to ask.
            'check_domains' => (bool) env('MAIL_CHECK_DOMAINS', true),
            // M18G: the first attempt by IPv4 as well — a cloud VM with no IPv6 route out waits out its whole timeout
            // on the relay's IPv6 address, and two sends on 27 Sep failed that way with no room left for the retry.
            'ipv4_first' => (bool) env('MAIL_IPV4_FIRST', true),
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Laravel')),
    ],

];
