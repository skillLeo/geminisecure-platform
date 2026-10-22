<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Live\Channels;
use App\Live\ChannelName;
use App\Live\Events\SmokeLive;
use App\Live\LiveBus;
use App\Live\Probe\SocketClient;
use App\Live\Stream;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Pusher\Pusher;
use Throwable;

/**
 *   php artisan live:smoke [--url=https://geminisl.com] [--within=2]
 *
 * THE WHOLE LIVE PATH, END TO END, FROM THE SERVER ITSELF (DESIGN.md section 10.3). No Node runs
 * in production, so this is the one test of a real socket there:
 *
 *   1  open wss://HOST/app/KEY with `Origin: https://HOST`, through nginx, as a browser does;
 *   2  subscribe `private-live.gemini.settings`, signed here for a probe principal (`u-smoke`);
 *   3  once subscribed, record a `live.smoke` event, and let the relay publish it;
 *   4  require it on the socket within `--within` seconds of being recorded.
 *
 * On failure it prints FAIL with the step that failed and exits non-zero. It rolls nothing back and
 * changes nothing a person sees: live degrades honestly, and every screen says so itself. 80-live.sh
 * runs it last. Pure ASCII.
 */
class LiveSmoke extends Command
{
    protected $signature = 'live:smoke
        {--url= : the site to connect through; the configured app URL when left out}
        {--within=2 : seconds the event may take to arrive}';

    protected $description = 'Open a real socket to Reverb, subscribe a live channel, record an event and require it back within 2 s';

    public function handle(): int
    {
        $base = rtrim((string) ($this->option('url') ?: config('app.url')), '/');
        $within = max(0.1, (float) $this->option('within'));
        $host = (string) parse_url($base, PHP_URL_HOST);
        $secure = parse_url($base, PHP_URL_SCHEME) === 'https';
        $port = parse_url($base, PHP_URL_PORT);
        $key = (string) config('broadcasting.connections.reverb.key');
        $secret = (string) config('broadcasting.connections.reverb.secret');
        $appId = (string) config('broadcasting.connections.reverb.app_id');

        if ($key === '' || $secret === '' || $appId === '') {
            $this->line('<fg=red>FAIL</> the reverb key, secret and app id are not configured.');

            return self::FAILURE;
        }

        $socketUrl = ($secure ? 'wss' : 'ws').'://'.$host.($port === null ? '' : ':'.$port).'/app/'.$key.'?protocol=7&client=php-smoke&version=1';
        $wire = ChannelName::WIRE_PREFIX.Channels::geminiFanIn('settings');
        $nonce = bin2hex(random_bytes(8));
        $recordedAt = null;
        $step = 'connecting';

        $this->line("Connecting to {$socketUrl}");

        $script = function (array $message, Closure $send) use ($key, $secret, $appId, $wire, $nonce, &$recordedAt, &$step): bool {
            $event = (string) ($message['event'] ?? '');
            $data = is_string($message['data'] ?? null) ? json_decode($message['data'], true) : ($message['data'] ?? null);

            if ($event === 'pusher:connection_established' && is_array($data) && is_string($data['socket_id'] ?? null)) {
                $step = 'subscribing';
                $channelData = (string) json_encode(['user_id' => 'u-smoke']);
                $auth = json_decode((new Pusher($key, $secret, $appId))->authorizeChannel($wire, $data['socket_id'], $channelData), true);
                $send(['event' => 'pusher:subscribe', 'data' => ['channel' => $wire, 'auth' => $auth['auth'] ?? '', 'channel_data' => $channelData]]);

                return false;
            }

            if ($event === 'pusher_internal:subscription_succeeded' && ($message['channel'] ?? null) === $wire) {
                $step = 'waiting for the event';
                DB::connection(Stream::central()->connection())->transaction(static fn () => LiveBus::record(SmokeLive::now($nonce)));
                $recordedAt = hrtime(true);

                return false;
            }

            if ($event === 'pusher:error') {
                throw new \RuntimeException('Reverb refused the socket or the subscription: '.json_encode($data));
            }

            if ($event === 'live' && ($message['channel'] ?? null) === $wire && is_array($data) && ($data['t'] ?? null) === SmokeLive::TYPE && ($data['i'] ?? null) === $nonce) {
                $step = 'received';

                return true;
            }

            return false;
        };

        try {
            // The socket's own budget: the connection, the subscription, then the event's `within`.
            $outcome = SocketClient::run($socketUrl, $base, $within + 8.0, $script);
        } catch (Throwable $e) {
            $this->line('<fg=red>FAIL</> '.class_basename($e).': '.$e->getMessage());

            return self::FAILURE;
        }

        $took = $recordedAt === null ? null : (hrtime(true) - $recordedAt) / 1e9;

        if ($outcome['done'] && $took !== null && $took <= $within) {
            $this->line(sprintf('<fg=green>PASS</> live.smoke arrived on %s %.0f ms after it was recorded (within %.1f s).', $wire, $took * 1000, $within));

            return self::SUCCESS;
        }

        $this->line('<fg=red>FAIL</> '.($outcome['done']
            ? sprintf('live.smoke arrived, but %.2f s after it was recorded: more than %.1f s.', $took ?? 0, $within)
            : "while {$step}: {$outcome['reason']}. Is gs-reverb running, does nginx pass /app/, and is gs-live-relay running?"));

        return self::FAILURE;
    }
}
