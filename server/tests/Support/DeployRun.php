<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * One run of a deploy script in a DeploySandbox: its exit status, everything it printed, and
 * every call it made to a stand-in, in order (`supervisorctl restart gs-reverb`, ...).
 */
final class DeployRun
{
    /** @param  list<string>  $calls */
    public function __construct(
        public readonly int $exit,
        public readonly string $output,
        public readonly array $calls,
    ) {}

    /** Whether any call starts with this text (`supervisorctl restart gs-reverb`). */
    public function called(string $prefix): bool
    {
        return $this->count($prefix) > 0;
    }

    public function count(string $prefix): int
    {
        return count(array_filter($this->calls, fn (string $call): bool => $call === $prefix || str_starts_with($call, $prefix.' ')));
    }

    /**
     * The calls that change something on a server, as opposed to asking it something.
     *
     * @return list<string>
     */
    public function changes(): array
    {
        $asks = [
            '#^supervisorctl (avail|pid|status)\b#',
            '#^apt-cache #',
            '#^pecl list#',
            '#^sudo #',
            '#^artisan\[[^\]]+\] (list|live:health|live:smoke)\b#',
            '#^systemctl is-active\b#',
            '#^curl #',
            '#^sleep #',
        ];

        return array_values(array_filter($this->calls, function (string $call) use ($asks): bool {
            foreach ($asks as $ask) {
                if (preg_match($ask, $call) === 1) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** Where a line of output first appears, or -1. */
    public function position(string $text): int
    {
        $at = strpos($this->output, $text);

        return $at === false ? -1 : $at;
    }

    public function explain(): string
    {
        return "exit {$this->exit}\n--- output ---\n{$this->output}\n--- calls ---\n".implode("\n", $this->calls)."\n";
    }
}
