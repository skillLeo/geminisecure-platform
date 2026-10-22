<?php

declare(strict_types=1);

namespace App\Services\Push;

use InvalidArgumentException;

/**
 * One notification, as the phone will show it and as it will route it (M4 §3.2).
 *
 * `data` IS EXACTLY WHAT `packages/core/notify/links.ts` UNDERSTANDS: a `type`, and
 * where the type needs them an `id` and a `kind` — each a short slug, never a URL,
 * a path or free text. Anything else is refused here, where a developer sees it,
 * rather than dropped on a phone where nobody does.
 *
 * WHAT THE WORDS MAY SAY. A lock screen is read by whoever is standing nearby, so a
 * title and body carry what the app's own local notification already shows and no
 * more: never a money amount (least of all to a guard), never anything about a
 * ballot's content, and a person's name only where the local notification names
 * them too (the visitor at the gate). `PushNotifier` writes every sentence; nothing
 * here is built from request input.
 */
final class PushMessage
{
    private const SLUG = '/^[A-Za-z0-9_-]{1,40}$/';

    public readonly string $title;

    public readonly string $body;

    public readonly ?string $id;

    public function __construct(
        public readonly string $type,
        string $title,
        string $body,
        int|string|null $id = null,
        public readonly ?string $kind = null,

        /** Seconds the push is worth delivering for. An alertness check nobody can still answer is noise. */
        public readonly ?int $ttl = null,

        /**
         * What "the same thing" is for coalescing, when it is wider than one record:
         * a roster published for a month is thirty shifts and ONE "your roster
         * changed". Left null, a message coalesces with others about the same `id`.
         */
        public readonly ?string $coalesceAs = null,
    ) {
        if (! PushTypes::known($type)) {
            throw new InvalidArgumentException("No notification type [{$type}] — the phones would not know where it leads.");
        }

        $id = $id === null ? null : (string) $id;

        foreach (['id' => $id, 'kind' => $kind] as $field => $value) {
            if ($value !== null && preg_match(self::SLUG, $value) !== 1) {
                throw new InvalidArgumentException("A push's `{$field}` is a short slug — letters, digits, dash, underscore — and nothing else.");
            }
        }

        $this->id = $id;
        $this->title = self::clip($title, 80);
        $this->body = self::clip($body, 170);
    }

    /**
     * The payload the phone routes on — and the whole of it.
     *
     * @return array{type: string, id?: string, kind?: string}
     */
    public function data(): array
    {
        return array_filter(['type' => $this->type, 'id' => $this->id, 'kind' => $this->kind], static fn (?string $v): bool => $v !== null);
    }

    /** What coalescing keys on: the record, and for a type with no record the type alone. */
    public function record(): string
    {
        return mb_substr($this->coalesceAs ?? $this->id ?? '', 0, 64);
    }

    public static function clip(string $text, int $max): string
    {
        $one = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($one) > $max ? mb_substr($one, 0, $max - 1).'…' : $one;
    }
}
