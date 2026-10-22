<?php

declare(strict_types=1);

namespace App\Live\Relay;

use App\Live\Stream;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * The relay's bell (DESIGN.md §1.3, §1.5).
 *
 *   poke   after a live event commits:  SADD live:dirty <c | e:{t}>;  LPUSH live:bell 1;  LTRIM live:bell 0 0
 *   wait   the relay:                   BRPOP live:bell 1;  then SPOP every member of live:dirty
 *
 * The set says WHICH streams have something new, however many commits rang; the list is only the
 * bell, trimmed to one entry so a thousand commits leave one ring and not a thousand. A ring that
 * is lost (Redis restarting, a process dying between its commit and its poke) loses nothing: the
 * relay's sweep reads every log itself, the central one each second and every estate's each 30 s.
 *
 * THE REDIS FACADE DIRECTLY, WITH NO TENANT PREFIX. Tenancy's Redis bootstrapper is off
 * (config/tenancy.php), so an estate's commit and the relay, which is in no estate, ring and hear
 * the same keys.
 *
 * With `live.wake` = none (the tests, a laptop without Redis) nothing is rung, and the relay looks
 * at every stream every 250 ms instead.
 */
final class Wake implements Bell, Waker
{
    public const DIRTY = 'live:dirty';

    public const BELL = 'live:bell';

    /** How often the relay looks at every stream when there is no bell to hear. */
    public const POLL_SECONDS = 0.25;

    public function __construct(private readonly Clock $clock) {}

    public static function enabled(): bool
    {
        return config('live.wake') === 'redis';
    }

    public function poke(Stream $stream): void
    {
        if (! self::enabled()) {
            return;
        }

        // The set before the bell: a relay woken by the ring always finds the stream it was rung for.
        $redis = $this->redis();
        $redis->sadd(self::DIRTY, $stream->key());
        $redis->lpush(self::BELL, '1');
        $redis->ltrim(self::BELL, 0, 0);
    }

    /**
     * Wait up to `$seconds` for the bell, then take every stream rung since the last take.
     *
     * BRPOP counts its timeout in whole seconds on the Redis versions this might meet, so a wait
     * shorter than one second (the relay has a timer due sooner) sleeps instead and then only
     * looks: nothing rung in that moment waits longer than the moment itself.
     *
     * With no bell (`live.wake` = none) it sleeps at most POLL_SECONDS and answers EVERYTHING.
     *
     * @return list<string> stream keys, `c` or `e:{tenant}`
     */
    public function wait(float $seconds): array
    {
        if (! self::enabled()) {
            $this->clock->sleep(min(max(0.0, $seconds), self::POLL_SECONDS));

            return [self::EVERYTHING];
        }

        $redis = $this->redis();

        if ($seconds >= 1.0) {
            $redis->brpop([self::BELL], (int) floor($seconds));
        } elseif ($seconds > 0) {
            $this->clock->sleep($seconds);
        }

        return $this->take($redis);
    }

    /** @return list<string> */
    private function take(Connection $redis): array
    {
        $keys = [];

        do {
            $popped = $redis->spop(self::DIRTY, 500);
            $batch = is_array($popped) ? $popped : (is_string($popped) ? [$popped] : []);

            foreach ($batch as $key) {
                $keys[(string) $key] = true;
            }
        } while (count($batch) === 500);

        return array_map(strval(...), array_keys($keys));
    }

    private function redis(): Connection
    {
        return Redis::connection((string) config('live.redis_connection', 'default'));
    }
}
