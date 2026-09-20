<?php

namespace Cooolinho\FilamentMailbox\Health;

use Cooolinho\FilamentMailbox\Enums\HealthCheckStatus;
use DateTimeInterface;
use Illuminate\Support\Carbon;

final class HealthCheckResult
{
    /**
     * @param  array<string, scalar|null>  $meta
     */
    public function __construct(
        public readonly HealthCheckStatus $status,
        public readonly string $message = '',
        public readonly array $meta = [],
        public ?DateTimeInterface $checkedAt = null,
    ) {
        $this->checkedAt ??= now();
    }

    /**
     * @param  array<string, scalar|null>  $meta
     */
    public static function ok(string $message = '', array $meta = []): self
    {
        return new self(HealthCheckStatus::Ok, $message, $meta);
    }

    /**
     * @param  array<string, scalar|null>  $meta
     */
    public static function warning(string $message, array $meta = []): self
    {
        return new self(HealthCheckStatus::Warning, $message, $meta);
    }

    /**
     * @param  array<string, scalar|null>  $meta
     */
    public static function failed(string $message, array $meta = []): self
    {
        return new self(HealthCheckStatus::Failed, $message, $meta);
    }

    public static function skipped(string $message = ''): self
    {
        return new self(HealthCheckStatus::Skipped, $message);
    }

    /**
     * @return array{status: string, message: string, meta: array<string, scalar|null>, checked_at: string}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'message' => $this->message,
            'meta' => $this->meta,
            'checked_at' => Carbon::instance($this->checkedAt)->toIso8601String(),
        ];
    }

    /**
     * @param  array{status: string, message?: string, meta?: array<string, scalar|null>, checked_at?: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            HealthCheckStatus::tryFrom($data['status']) ?? HealthCheckStatus::Failed,
            (string) ($data['message'] ?? ''),
            (array) ($data['meta'] ?? []),
            isset($data['checked_at']) ? Carbon::parse($data['checked_at']) : null,
        );
    }
}
