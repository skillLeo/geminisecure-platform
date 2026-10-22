<?php

declare(strict_types=1);

namespace App\Mail;

use Closure;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * THE PLATFORM'S SMTP, TRIED AGAIN BEFORE IT GIVES UP (M12 §5.1: "25 Sep 18:58 UTC, connection to Hostinger timed
 * out … This matters now that every account verifies by email: a code that never arrives locks someone out. Add a
 * retry with backoff, and surface failures where an admin can see them").
 *
 * Registered as the `smtp` transport itself (AppServiceProvider), so every message the platform sends — a resident's
 * sign-in code, an invitation, a password reset, a contact-verification code, a panic's email — goes through it, by
 * whatever relay the settings name (`.env`, or MailSettings from the console).
 *
 *   THREE ATTEMPTS, with 1 s and then 3 s between them, each on a fresh connection.
 *   INSIDE A BUDGET. No attempt is begun more than BUDGET_SECONDS after the first: a sign-in code is awaited by a
 *     phone that gives up at fifteen seconds, and a retry the person will never see answered is no help to them.
 *   THE SECOND AND THIRD GO BY IPv4. smtp.hostinger.com has an IPv6 address as well as an IPv4 one, and a Google
 *     Cloud VM has no IPv6 route out unless it is given one. A connection that tries the IPv6 address first, into a
 *     network that silently drops it, waits out the whole timeout and reports "connection timed out" — the error of
 *     25 September — without ever trying the address that works. So a retry connects to the relay's IPv4 address,
 *     still verifying the certificate against the relay's NAME.
 *   AND THEN IT SAYS SO. A message given up on is recorded (`mail_failures`, append-only, the recipient masked) for
 *     the Email settings page and the bell, and the exception goes on up — the caller's own honesty (a sign-in code
 *     "could not be emailed just now") is untouched.
 */
final class ResilientSmtpTransport implements TransportInterface
{
    public const ATTEMPTS = 3;

    /** Seconds before the second and the third attempt. */
    public const BACKOFF = [1, 3];

    /** No attempt is begun later than this after the first one began. */
    public const BUDGET_SECONDS = 12;

    /**
     * @param  array<string, mixed>  $config  the `smtp` mailer's configuration
     * @param  Closure(RawMessage, ?Envelope, int, \Throwable, string): void  $giveUp  what is done with a message given up on
     * @param  (Closure(bool): TransportInterface)|null  $build  a transport for one attempt (true: by IPv4); tests pass their own
     * @param  (Closure(int): void)|null  $sleep
     */
    public function __construct(
        private readonly array $config,
        private readonly Closure $giveUp,
        private readonly ?Closure $build = null,
        private readonly ?Closure $sleep = null,
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $began = microtime(true);
        $tried = 0;
        $last = null;

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            if ($attempt > 1) {
                $wait = self::BACKOFF[$attempt - 2];

                if (microtime(true) - $began + $wait > self::BUDGET_SECONDS) {
                    break;
                }

                if ($this->sleep !== null) {
                    ($this->sleep)($wait);
                } elseif ((bool) ($this->config['retry_backoff'] ?? true)) {
                    sleep($wait);
                }
            }

            $tried++;

            try {
                return ($this->build ?? $this->transport(...))($attempt > 1 || $this->ipv4First())->send($message, $envelope);
            } catch (TransportExceptionInterface $e) {
                $last = $e;
            }
        }

        ($this->giveUp)($message, $envelope, $tried, $last, $this->relay());

        throw $last;
    }

    public function __toString(): string
    {
        return 'smtp+retry://'.$this->relay();
    }

    /**
     * THE FIRST ATTEMPT BY IPv4 TOO (M18G, production 27 Sep). Two sends failed that day — a panic's escalation mail and
     * a resident's sign-in code — each recorded with ONE attempt: the first, by name, waited out its whole timeout on the
     * relay's IPv6 address, which a Google Cloud VM cannot reach, and by then the budget left no room for the IPv4 retry
     * that would have worked. So on a server with no IPv6 route out, IPv4 comes first (`MAIL_IPV4_FIRST`, on unless
     * switched off). The retries still go by IPv4 whatever this says.
     */
    private function ipv4First(): bool
    {
        return (bool) ($this->config['ipv4_first'] ?? true);
    }

    /** "smtp.hostinger.com:465" */
    public function relay(): string
    {
        return (string) ($this->config['host'] ?? '').':'.(string) ($this->config['port'] ?? '');
    }

    /** Symfony's SMTP transport for a DSN, as Laravel makes its own: `smtp` and `smtps` are always an EsmtpTransport. */
    public static function esmtp(Dsn $dsn): EsmtpTransport
    {
        $transport = (new EsmtpTransportFactory)->create($dsn);

        if (! $transport instanceof EsmtpTransport) {
            throw new \LogicException('The SMTP factory made a '.get_debug_type($transport).' for '.$dsn->getScheme().'://, not an ESMTP transport.');
        }

        return $transport;
    }

    /** One attempt's transport, built as Laravel builds its own `smtp` one — by IPv4 when asked. */
    private function transport(bool $ipv4): TransportInterface
    {
        $host = (string) ($this->config['host'] ?? '127.0.0.1');
        $port = (int) ($this->config['port'] ?? 587);
        $scheme = $this->config['scheme'] ?? null;
        $scheme = is_string($scheme) && $scheme !== '' ? $scheme : ($port === 465 ? 'smtps' : 'smtp');

        // gethostbyname() answers with an IPv4 address, or with the name unchanged when it cannot.
        $connectTo = $ipv4 && filter_var($host, FILTER_VALIDATE_IP) === false ? gethostbyname($host) : $host;

        $transport = self::esmtp(new Dsn(
            $scheme,
            $connectTo,
            isset($this->config['username']) ? (string) $this->config['username'] : null,
            isset($this->config['password']) ? (string) $this->config['password'] : null,
            $port,
            $this->config,
        ));

        $stream = $transport->getStream();

        if ($stream instanceof SocketStream) {
            if (isset($this->config['timeout'])) {
                $stream->setTimeout((float) $this->config['timeout']);
            }

            if (isset($this->config['source_ip']) && is_string($this->config['source_ip']) && $this->config['source_ip'] !== '') {
                $stream->setSourceIp($this->config['source_ip']);
            }

            if ($connectTo !== $host) {
                // By address, but the certificate is the relay's NAME: it is verified against the name.
                $options = $stream->getStreamOptions();
                $options['ssl']['peer_name'] = $host;
                $stream->setStreamOptions($options);
            }
        }

        return $transport;
    }
}
