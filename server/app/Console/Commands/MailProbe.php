<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\ResilientSmtpTransport;
use App\Services\Platform\MailSettings;
use Illuminate\Console\Command;
use Symfony\Component\Mailer\Transport\Dsn;
use Throwable;

/**
 * WHY DID THE RELAY NOT ANSWER? — asked from the server itself (M12 §5.1: "Find out why — timeout, port, TLS, rate
 * limit, outbound port blocked on the VM — and fix it").
 *
 *   php artisan mail:probe                  every address the relay's name gives, on its port and on the other one
 *                                           (465 ↔ 587), each connected and timed; then the TLS handshake and the
 *                                           greeting; then a login (no message is sent)
 *   php artisan mail:probe --send=a@b.c     and then one real test message, through the platform's own transport
 *
 * It names each cause it can see: an IPv6 address the VM cannot reach, a port the VM's firewall drops, a TLS
 * mismatch, a refused login, a slow greeting. The password is used and never printed.
 */
final class MailProbe extends Command
{
    protected $signature = 'mail:probe {--send= : also send one test message to this address} {--timeout=8 : seconds per connection}';

    protected $description = 'Diagnose the SMTP relay from this server: DNS, each address and port, TLS, greeting, login';

    public function handle(MailSettings $settings): int
    {
        $settings->apply();

        $host = (string) config('mail.mailers.smtp.host');
        $port = (int) config('mail.mailers.smtp.port');
        $timeout = (float) $this->option('timeout');

        $this->line("Mailer: ".config('mail.default')."   relay: {$host}:{$port}   scheme: ".(config('mail.mailers.smtp.scheme') ?: '(none: STARTTLS on 587)'));

        $a = array_column(dns_get_record($host, DNS_A) ?: [], 'ip');
        $aaaa = array_column(dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');
        $this->line('DNS A:    '.($a === [] ? '(none)' : implode(', ', $a)));
        $this->line('DNS AAAA: '.($aaaa === [] ? '(none)' : implode(', ', $aaaa)));

        $problems = [];

        foreach (array_unique([$port, $port === 465 ? 587 : 465]) as $p) {
            foreach ([...$a, ...$aaaa] as $ip) {
                $target = str_contains($ip, ':') ? "[{$ip}]" : $ip;
                $began = microtime(true);
                $socket = @stream_socket_client("tcp://{$target}:{$p}", $errno, $error, $timeout);
                $ms = (int) round((microtime(true) - $began) * 1000);

                if ($socket === false) {
                    $this->line(sprintf('  connect %-40s %5d ms  FAILED: %s', "{$target}:{$p}", $ms, $error ?: "errno {$errno}"));
                    $problems[] = str_contains($ip, ':')
                        ? "IPv6 {$ip}:{$p} does not answer from this server — a connection that tries it first waits out the timeout (the transport retries by IPv4)."
                        : "IPv4 {$ip}:{$p} does not answer from this server — an outbound firewall rule or the relay is refusing this port.";

                    continue;
                }

                fclose($socket);
                $this->line(sprintf('  connect %-40s %5d ms  ok', "{$target}:{$p}", $ms));
            }
        }

        // The relay's own greeting, over the TLS it asks for, by name (as the platform connects).
        $began = microtime(true);
        $context = stream_context_create(['ssl' => ['peer_name' => $host, 'verify_peer' => true, 'verify_peer_name' => true]]);
        $url = $port === 465 ? "ssl://{$host}:465" : "tcp://{$host}:{$port}";
        $stream = @stream_socket_client($url, $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);

        if ($stream === false) {
            $this->line(sprintf('Greeting over %s: FAILED after %d ms: %s', $url, (int) round((microtime(true) - $began) * 1000), $error ?: "errno {$errno}"));
            $problems[] = "The relay could not be reached by name at {$url}.";
        } else {
            stream_set_timeout($stream, (int) ceil($timeout));
            $greeting = trim((string) fgets($stream));
            $this->line(sprintf('Greeting over %s: %d ms: %s', $url, (int) round((microtime(true) - $began) * 1000), mb_substr($greeting, 0, 80)));
            fclose($stream);

            if (! str_starts_with($greeting, '220')) {
                $problems[] = 'The relay did not greet with 220 — it may be rate-limiting this server.';
            }
        }

        // A login, without a message: the transport's own start().
        try {
            $began = microtime(true);
            $scheme = (string) (config('mail.mailers.smtp.scheme') ?: ($port === 465 ? 'smtps' : 'smtp'));
            $transport = ResilientSmtpTransport::esmtp(new Dsn($scheme, $host, (string) config('mail.mailers.smtp.username'), (string) config('mail.mailers.smtp.password'), $port));
            $transport->start();
            $transport->stop();
            $this->line(sprintf('Login: ok in %d ms (no message sent)', (int) round((microtime(true) - $began) * 1000)));
        } catch (Throwable $e) {
            $this->line('Login: FAILED — '.$settings->explain($e));
            $problems[] = 'Login failed: '.$settings->explain($e);
        }

        if (is_string($this->option('send')) && $this->option('send') !== '') {
            $result = $settings->sendTest((string) $this->option('send'));
            $this->line(($result['ok'] ? 'Test message: SENT — ' : 'Test message: NOT SENT — ').$result['message']);

            if (! $result['ok']) {
                $problems[] = 'The test message was not sent.';
            }
        }

        if ($problems === []) {
            $this->info('The relay answers on every address and port it gives, and accepts this platform\'s login.');

            return self::SUCCESS;
        }

        $this->warn('What this server sees:');

        foreach (array_unique($problems) as $problem) {
            $this->line('  - '.$problem);
        }

        return self::FAILURE;
    }
}
