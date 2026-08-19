<?php

namespace App\Services\Scoreboard;

/**
 * What came back from a scoreboard. A value object rather than a bare bool so
 * a failure carries enough detail to appear in the audit trail and the log.
 */
final readonly class ScoreboardResponse
{
    /** @param  array<string, mixed>  $body */
    public function __construct(
        public bool $successful,
        public ?int $status = null,
        public ?string $message = null,
        public array $body = [],
    ) {}

    /** @param  array<string, mixed>  $body */
    public static function ok(int $status = 200, array $body = []): self
    {
        return new self(true, $status, null, $body);
    }

    public static function failure(string $message, ?int $status = null): self
    {
        return new self(false, $status, $message);
    }

    public function failed(): bool
    {
        return ! $this->successful;
    }

    /** @return array<string, mixed> */
    public function toLogContext(): array
    {
        return array_filter([
            'successful' => $this->successful,
            'status' => $this->status,
            'message' => $this->message,
        ], fn ($v) => $v !== null);
    }
}
