<?php

declare(strict_types=1);

namespace App\Api;

use RuntimeException;

/**
 * A refusal the mobile API names (13 D1).
 *
 * Every error a handset can receive outside validation has this shape:
 *
 *   { "error": { "code": "not_your_shift", "message": "…" } }
 *
 * `code` is stable and what an app branches on; `message` is a sentence a guard
 * or resident can be shown, and may change. The codes each endpoint can return
 * are listed on it in the `Catalogue`, and so in MOBILE_HANDOFF.md.
 */
final class ApiError extends RuntimeException
{
    /**
     * @param  array<string, string>  $headers  sent with the refusal — `Retry-After` on a 429, which the catalogue promises says when
     * @param  array<string, mixed>  $details  what the app needs to act on this refusal beyond its sentence — sent as `error.details`
     *                                         only when there is some (`account_deleted` carries its dates and the restore ticket)
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $headers = [],
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function forbidden(string $code, string $message): self
    {
        return new self(403, $code, $message);
    }

    public static function notFound(string $code, string $message): self
    {
        return new self(404, $code, $message);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self(409, $code, $message);
    }

    public static function unprocessable(string $code, string $message): self
    {
        return new self(422, $code, $message);
    }

    /** @return array{error: array{code: string, message: string, details?: array<string, mixed>}} */
    public function body(): array
    {
        $error = ['code' => $this->errorCode, 'message' => $this->getMessage()];

        return ['error' => $this->details === [] ? $error : [...$error, 'details' => $this->details]];
    }
}
