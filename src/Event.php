<?php

declare(strict_types=1);

namespace HelpVox;

/**
 * One event delivered to the app's webhooks.url (contract section 7).
 *
 * `id` is the same on every retry — store it and skip events you already processed.
 */
final class Event
{
    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $raw  the whole decoded body
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $createdAt,
        public readonly string $vendorId,
        public readonly string $installationId,
        public readonly array $data,
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string,mixed> $body */
    public static function fromArray(array $body): self
    {
        $data = $body['data'] ?? [];

        return new self(
            id: (string) ($body['id'] ?? ''),
            type: (string) ($body['type'] ?? ''),
            createdAt: (string) ($body['created_at'] ?? ''),
            vendorId: (string) ($body['vendor_id'] ?? ''),
            installationId: (string) ($body['installation_id'] ?? ''),
            data: \is_array($data) ? $data : [],
            raw: $body,
        );
    }

    /** True for events sent with `POST /api/v1/app/events/test` (`data.test: true`). */
    public function isTest(): bool
    {
        return ($this->data['test'] ?? false) === true;
    }

    public function createdAtTime(): ?\DateTimeImmutable
    {
        if ($this->createdAt === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($this->createdAt);
        } catch (\Exception) {
            return null;
        }
    }
}
