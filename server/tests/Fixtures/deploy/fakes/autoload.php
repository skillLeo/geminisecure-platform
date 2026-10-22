<?php

declare(strict_types=1);

/*
 * A release's vendor/autoload.php, standing in for react/event-loop: Loop::get() answers the
 * class the real Factory would pick with the extension PHP loads here (state/loop-ext), or
 * StreamSelectLoop when there is none (V:vendor/react/event-loop/src/Factory.php).
 */

namespace React\EventLoop;

final class StreamSelectLoop {}

final class ExtEvLoop {}

final class ExtEventLoop {}

final class Loop
{
    public static function get(): object
    {
        $file = rtrim((string) getenv('GS_FAKE_STATE'), '/\\').'/loop-ext';
        $ext = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return match ($ext) {
            'ev' => new ExtEvLoop,
            'event' => new ExtEventLoop,
            default => new StreamSelectLoop,
        };
    }

    public static function stop(): void {}
}
